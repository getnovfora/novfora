<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Deliverability\Bounce\BounceMailbox;
use App\Deliverability\Bounce\BounceParser;
use App\Deliverability\DeliverabilityManager;
use App\Deliverability\Suppressor;
use App\Deliverability\Verp;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| GO CRITERION 2 — bounce → suppression, no daemon. A hard bounce / complaint on any path auto-suppresses;
| later sends skip it; it is visible in the ACP. A transient 4.x.x is parsed but NEVER suppressed.
|
| SECURITY (post-review HIGH fix): the bounce mailbox is fed by the open internet, so identity is taken ONLY
| from a cryptographically-signed VERP address — never from attacker-controlled body headers. The "suppress an
| arbitrary victim" tests below pin that. (Webhook HMAC path: see WebhookSecurityTest.)
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'hearth.deliverability.enabled' => true,
        'hearth.deliverability.verp.enabled' => true,
        'hearth.deliverability.verp.domain' => 'bounce.example.com',
        'hearth.deliverability.verp.key' => 'a-test-verp-key',
    ]);
});

/** A user plus the signed VERP address a bounce TO them would be delivered to. */
function verpUser(string $email): array
{
    $user = User::factory()->create(['email' => $email]);

    return [$user, app(Verp::class)->returnPathFor((int) $user->getKey(), 1)];
}

/** A DSN delivered to our signed VERP address $deliveredTo, with the given status + optional Final-Recipient. */
function dsnTo(string $deliveredTo, string $status, string $finalRecipient = ''): string
{
    $fr = $finalRecipient !== '' ? "Final-Recipient: rfc822; {$finalRecipient}\n" : '';

    return implode("\n", [
        'Return-Path: <>',
        $deliveredTo !== '' ? "Delivered-To: {$deliveredTo}" : 'Delivered-To: mailer-daemon@host',
        'Content-Type: multipart/report; report-type=delivery-status; boundary="b"',
        '',
        '--b',
        'Content-Type: message/delivery-status',
        '',
        $fr.'Action: failed',
        "Status: {$status}",
        '',
        '--b--',
    ]);
}

/** An ARF complaint delivered to our signed VERP address. */
function arfTo(string $deliveredTo, string $originalRcptTo = ''): string
{
    $orig = $originalRcptTo !== '' ? "Original-Rcpt-To: rfc822; {$originalRcptTo}\n" : '';

    return implode("\n", [
        "Delivered-To: {$deliveredTo}",
        'Content-Type: multipart/report; report-type=feedback-report; boundary="b"',
        '',
        '--b',
        'Content-Type: message/feedback-report',
        '',
        'Feedback-Type: abuse',
        $orig.'User-Agent: ExampleProvider/1',
        '',
        '--b--',
    ]);
}

it('suppresses a hard bounce (5.x.x) authenticated by a signed VERP recipient', function () {
    [$user, $verp] = verpUser('bob@example.com');
    $events = app(BounceParser::class)->parse(dsnTo($verp, '5.1.1'));

    expect($events)->toHaveCount(1)
        ->and($events[0]->email)->toBe('bob@example.com')
        ->and($events[0]->shouldSuppress())->toBeTrue();

    app(Suppressor::class)->applyEvent($events[0]);
    expect(EmailSuppression::where('email', 'bob@example.com')->where('reason', 'bounce')->exists())->toBeTrue();
});

it('does NOT suppress a transient (4.x.x) bounce', function () {
    [, $verp] = verpUser('temp@example.com');
    $events = app(BounceParser::class)->parse(dsnTo($verp, '4.2.2'));

    expect($events)->toHaveCount(1)->and($events[0]->shouldSuppress())->toBeFalse();
    app(Suppressor::class)->applyEvent($events[0]);
    expect(EmailSuppression::where('email', 'temp@example.com')->exists())->toBeFalse();
});

it('suppresses a complaint (ARF) authenticated by a signed VERP recipient', function () {
    [, $verp] = verpUser('carol@example.com');
    $events = app(BounceParser::class)->parse(arfTo($verp));

    expect($events)->toHaveCount(1)->and($events[0]->reason())->toBe('complaint');
    app(Suppressor::class)->applyEvent($events[0]);
    expect(EmailSuppression::where('email', 'carol@example.com')->where('reason', 'complaint')->exists())->toBeTrue();
});

it('REFUSES to suppress a victim named in an unauthenticated Final-Recipient (suppression-as-DoS)', function () {
    User::factory()->create(['email' => 'victim@example.com']);

    // A hostile DSN: a real victim in Final-Recipient, but NO signed VERP recipient (delivered to a plain addr).
    $events = app(BounceParser::class)->parse(dsnTo('', '5.0.0', 'victim@example.com'));

    expect($events)->toBeEmpty();
    expect(EmailSuppression::where('email', 'victim@example.com')->exists())->toBeFalse();
});

it('REFUSES to suppress when the VERP recipient is forged (bad signature)', function () {
    $victim = User::factory()->create(['email' => 'victim@example.com']);
    $forged = "bounce+{$victim->getKey()}.1.deadbeefdeadbeef@bounce.example.com";

    expect(app(Verp::class)->decode($forged))->toBeNull();

    $events = app(BounceParser::class)->parse(dsnTo($forged, '5.0.0', 'victim@example.com'));
    expect($events)->toBeEmpty();
    expect(EmailSuppression::where('email', 'victim@example.com')->exists())->toBeFalse();
});

it('REFUSES to suppress from an ARF body recipient when VERP is absent', function () {
    User::factory()->create(['email' => 'victim@example.com']);

    $events = app(BounceParser::class)->parse(arfTo('mailer-daemon@host', 'victim@example.com'));
    expect($events)->toBeEmpty();
    expect(EmailSuppression::where('email', 'victim@example.com')->exists())->toBeFalse();
});

it('auto-suppresses NOTHING from a polled mailbox when VERP is disabled (manual-review baseline)', function () {
    config(['hearth.deliverability.verp.enabled' => false]);
    User::factory()->create(['email' => 'real@example.com']);

    // Even a perfectly well-formed DSN cannot be authenticated without VERP → no auto-suppression.
    $events = app(BounceParser::class)->parse(dsnTo('bounce+1.1.x@bounce.example.com', '5.0.0', 'real@example.com'));
    expect($events)->toBeEmpty();
});

it('polls a configured bounce mailbox and suppresses only authenticated hard bounces, never throwing', function () {
    [, $verpHard] = verpUser('eve@example.com');
    [, $verpSoft] = verpUser('transient@example.com');

    $this->app->instance(BounceMailbox::class, new class($verpHard, $verpSoft) implements BounceMailbox
    {
        public function __construct(private string $hard, private string $soft) {}

        public function available(): bool
        {
            return true;
        }

        public function fetch(int $limit): array
        {
            return [dsnTo($this->hard, '5.1.1'), dsnTo($this->soft, '4.0.0')];
        }
    });

    $suppressed = app(DeliverabilityManager::class)->ingestAvailable();

    expect($suppressed)->toBe(1) // only the hard bounce
        ->and(EmailSuppression::where('email', 'eve@example.com')->exists())->toBeTrue()
        ->and(EmailSuppression::where('email', 'transient@example.com')->exists())->toBeFalse();
});

it('never throws on a malformed / hostile message', function () {
    expect(app(BounceParser::class)->parse('this is not a bounce at all <<>>'))->toBeEmpty();
    expect(app(BounceParser::class)->parse(''))->toBeEmpty();
});

it('shows suppressions in the ACP and lets an admin add / remove one', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    EmailSuppression::create(['email' => 'shown@example.com', 'reason' => 'bounce', 'created_at' => now()]);

    $this->get(route('admin.system.suppressions'))->assertOk()->assertSee('shown@example.com');

    Livewire::test('admin.suppressions')
        ->set('newEmail', 'manual@example.com')
        ->call('add')
        ->assertHasNoErrors();
    expect(EmailSuppression::where('email', 'manual@example.com')->where('reason', 'manual')->exists())->toBeTrue();

    Livewire::test('admin.suppressions')->call('remove', 'shown@example.com');
    expect(EmailSuppression::where('email', 'shown@example.com')->exists())->toBeFalse();
});
