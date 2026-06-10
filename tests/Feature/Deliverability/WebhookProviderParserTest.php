<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Deliverability\Suppressor;
use App\Deliverability\Webhook\ProviderWebhookParser;
use App\Deliverability\Webhook\WebhookVerifier;
use App\Http\Controllers\MailWebhookController;
use App\Models\EmailSuppression;
use App\Models\MailWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
| P2-M2 Half-A — SES + Mailgun webhook parsers (untrusted bytes). The parser is TOTAL and conservative: it
| maps documented shapes, never throws, and on ambiguity prefers NOT suppressing. The controller's HMAC /
| replay / size guards apply to every provider — pinned here for ses + mailgun like the Postmark battery.
*/

uses(RefreshDatabase::class);

const PROV_WEBHOOK_SECRET = 'a-long-random-per-install-secret-prov';

beforeEach(function () {
    config([
        'hearth.deliverability.enabled' => true,
        'hearth.deliverability.webhook.enabled' => true,
        'hearth.deliverability.webhook.secret' => PROV_WEBHOOK_SECRET,
        'hearth.deliverability.webhook.max_body_bytes' => 262144,
        'hearth.deliverability.webhook.tolerance_seconds' => 300,
    ]);
});

function provParse(string $provider, array $payload): array
{
    return app(ProviderWebhookParser::class)->parse($provider, $payload);
}

function provWebhookCall(string $raw, ?string $sig, ?string $ts, string $provider): JsonResponse
{
    $request = Request::create("/webhooks/mail/{$provider}", 'POST', [], [], [], [], $raw);
    if ($sig !== null) {
        $request->headers->set('X-Hearth-Signature', $sig);
    }
    if ($ts !== null) {
        $request->headers->set('X-Hearth-Timestamp', $ts);
    }

    return app(MailWebhookController::class)->__invoke(
        $request, $provider, app(WebhookVerifier::class), app(ProviderWebhookParser::class), app(Suppressor::class),
    );
}

/** @return array{0:string,1:string} [timestamp, signature] over "{ts}.{raw}". */
function provSign(string $raw, ?string $ts = null): array
{
    $ts ??= (string) now()->getTimestamp();

    return [$ts, hash_hmac('sha256', "{$ts}.{$raw}", PROV_WEBHOOK_SECRET)];
}

// ── SES mapping ────────────────────────────────────────────────────────────────────────────────────────

it('maps an SES permanent bounce to a suppressing event', function () {
    $result = provParse('ses', [
        'notificationType' => 'Bounce',
        'bounce' => ['bounceType' => 'Permanent', 'feedbackId' => 'fb-1', 'bouncedRecipients' => [['emailAddress' => 'Bob@Example.com']]],
        'mail' => ['messageId' => 'm-1'],
    ]);

    expect($result['events'])->toHaveCount(1)
        ->and($result['events'][0]->email)->toBe('bob@example.com')
        ->and($result['events'][0]->shouldSuppress())->toBeTrue()
        ->and($result['eventKey'])->toBe('fb-1');
});

it('does NOT suppress an SES transient bounce', function () {
    $result = provParse('ses', [
        'notificationType' => 'Bounce',
        'bounce' => ['bounceType' => 'Transient', 'bouncedRecipients' => [['emailAddress' => 'temp@example.com']]],
    ]);

    expect($result['events'])->toHaveCount(1)->and($result['events'][0]->shouldSuppress())->toBeFalse();
});

it('maps an SES complaint to a complaint event', function () {
    $result = provParse('ses', [
        'notificationType' => 'Complaint',
        'complaint' => ['complainedRecipients' => [['emailAddress' => 'carol@example.com']]],
    ]);

    expect($result['events'])->toHaveCount(1)->and($result['events'][0]->reason())->toBe('complaint');
});

it('unwraps an SNS-wrapped SES bounce', function () {
    $inner = json_encode([
        'notificationType' => 'Bounce',
        'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'sns@example.com']]],
    ]);
    $result = provParse('ses', ['Type' => 'Notification', 'MessageId' => 'sns-1', 'Message' => $inner]);

    expect($result['events'])->toHaveCount(1)
        ->and($result['events'][0]->email)->toBe('sns@example.com')
        ->and($result['events'][0]->shouldSuppress())->toBeTrue();
});

it('emits one event per SES bounced recipient', function () {
    $result = provParse('ses', [
        'notificationType' => 'Bounce',
        'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'a@example.com'], ['emailAddress' => 'b@example.com']]],
    ]);

    expect($result['events'])->toHaveCount(2);
});

it('returns no event for an SES delivery / unknown / garbage notification (total)', function () {
    expect(provParse('ses', ['notificationType' => 'Delivery', 'mail' => ['messageId' => 'd-1']])['events'])->toBeEmpty()
        ->and(provParse('ses', ['Type' => 'Notification', 'Message' => 'not json'])['events'])->toBeEmpty()
        ->and(provParse('ses', ['garbage' => true])['events'])->toBeEmpty();
});

// ── Mailgun mapping ──────────────────────────────────────────────────────────────────────────────────────

it('maps a Mailgun permanent failure to a suppressing event', function () {
    $result = provParse('mailgun', ['event-data' => ['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'dan@example.com', 'id' => 'mg-1']]);

    expect($result['events'])->toHaveCount(1)
        ->and($result['events'][0]->email)->toBe('dan@example.com')
        ->and($result['events'][0]->shouldSuppress())->toBeTrue()
        ->and($result['eventKey'])->toBe('mg-1');
});

it('does NOT suppress a Mailgun temporary failure (or missing severity)', function () {
    expect(provParse('mailgun', ['event-data' => ['event' => 'failed', 'severity' => 'temporary', 'recipient' => 'soft@example.com']])['events'][0]->shouldSuppress())->toBeFalse()
        ->and(provParse('mailgun', ['event-data' => ['event' => 'failed', 'recipient' => 'soft2@example.com']])['events'][0]->shouldSuppress())->toBeFalse();
});

it('maps a Mailgun complaint', function () {
    $result = provParse('mailgun', ['event-data' => ['event' => 'complained', 'recipient' => 'erin@example.com']]);

    expect($result['events'])->toHaveCount(1)->and($result['events'][0]->reason())->toBe('complaint');
});

it('returns no event for a Mailgun delivered/garbage event (total)', function () {
    expect(provParse('mailgun', ['event-data' => ['event' => 'delivered', 'recipient' => 'ok@example.com']])['events'])->toBeEmpty()
        ->and(provParse('mailgun', ['event-data' => ['event' => 'failed', 'severity' => 'permanent']])['events'])->toBeEmpty() // no recipient
        ->and(provParse('mailgun', ['nonsense' => 1])['events'])->toBeEmpty();
});

// ── controller security, pinned per provider (HMAC / replay / oversize) ──────────────────────────────────

it('SES: a forged signature is rejected 401 with no suppression', function () {
    $raw = json_encode(['notificationType' => 'Bounce', 'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'victim@example.com']]]]);
    [$ts] = provSign($raw);

    $response = provWebhookCall($raw, 'deadbeef'.str_repeat('0', 56), $ts, 'ses');

    expect($response->getStatusCode())->toBe(401)
        ->and(EmailSuppression::count())->toBe(0);
});

it('Mailgun: a valid-HMAC permanent failure suppresses, and a replay applies it at most once', function () {
    $raw = json_encode(['event-data' => ['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'frank@example.com', 'id' => 'mg-replay']]);
    [$ts, $sig] = provSign($raw);

    $first = provWebhookCall($raw, $sig, $ts, 'mailgun');
    $second = provWebhookCall($raw, $sig, $ts, 'mailgun');

    expect($first->getStatusCode())->toBe(200)
        ->and($second->getStatusCode())->toBe(200)
        ->and(EmailSuppression::where('email', 'frank@example.com')->count())->toBe(1)
        ->and(MailWebhookEvent::count())->toBe(1);
});

it('SES: an oversize body is rejected 413 before parsing', function () {
    config(['hearth.deliverability.webhook.max_body_bytes' => 32]);
    $raw = json_encode(['notificationType' => 'Bounce', 'pad' => str_repeat('x', 200)]);
    [$ts, $sig] = provSign($raw);

    expect(provWebhookCall($raw, $sig, $ts, 'ses')->getStatusCode())->toBe(413);
});

it('Mailgun: a malformed JSON body is rejected 422, never 500', function () {
    $raw = 'this is not json';
    [$ts, $sig] = provSign($raw);

    expect(provWebhookCall($raw, $sig, $ts, 'mailgun')->getStatusCode())->toBe(422);
});
