<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Deliverability\Bounce\BounceMailbox;
use App\Deliverability\Bounce\BounceParser;
use App\Deliverability\DeliverabilityManager;
use App\Models\BounceReview;
use App\Models\EmailSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| P2-M2 Half-A — the non-VERP bounce manual-review queue (spike-p2-memo §2b / §8). A polled mailbox WITHOUT
| VERP can't authenticate a sender-supplied address, so it auto-suppresses NOTHING; a permanent-bounce /
| complaint is instead queued UNVERIFIED for staff to suppress (or dismiss) by hand in the ACP. A bounce to a
| forged/absent VERP address while VERP IS enabled is NOT queued (no review-queue flood / DoS).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'novfora.deliverability.enabled' => true,
        'novfora.deliverability.verp.enabled' => false, // the non-VERP regime
    ]);
});

function reviewDsn(string $finalRecipient, string $status): string
{
    return implode("\n", [
        'Delivered-To: mailer-daemon@host',
        'Content-Type: multipart/report; report-type=delivery-status; boundary="b"',
        '',
        '--b',
        'Content-Type: message/delivery-status',
        '',
        "Final-Recipient: rfc822; {$finalRecipient}",
        'Action: failed',
        "Status: {$status}",
        '',
        '--b--',
    ]);
}

function reviewArf(string $originalRcptTo): string
{
    return implode("\n", [
        'Delivered-To: mailer-daemon@host',
        'Content-Type: multipart/report; report-type=feedback-report; boundary="b"',
        '',
        '--b',
        'Content-Type: message/feedback-report',
        '',
        'Feedback-Type: abuse',
        "Original-Rcpt-To: rfc822; {$originalRcptTo}",
        '',
        '--b--',
    ]);
}

/** Bind a mailbox returning the given raw messages. */
function reviewMailbox(array $messages): void
{
    app()->instance(BounceMailbox::class, new class($messages) implements BounceMailbox
    {
        public function __construct(private array $messages) {}

        public function available(): bool
        {
            return true;
        }

        public function fetch(int $limit): array
        {
            return $this->messages;
        }
    });
}

it('parses a permanent-bounce review candidate when VERP is disabled', function () {
    $candidate = app(BounceParser::class)->reviewCandidate(reviewDsn('Bob@Example.com', '5.1.1'));

    expect($candidate)->not->toBeNull()
        ->and($candidate['email'])->toBe('bob@example.com')
        ->and($candidate['type'])->toBe('bounce')
        ->and($candidate['excerpt'])->toContain('Status: 5.1.1');
});

it('queues a non-VERP permanent bounce for review and auto-suppresses NOTHING', function () {
    reviewMailbox([reviewDsn('real@example.com', '5.0.0')]);

    $suppressed = app(DeliverabilityManager::class)->ingestAvailable();

    expect($suppressed)->toBe(0)
        ->and(EmailSuppression::count())->toBe(0)
        ->and(BounceReview::where('candidate_email', 'real@example.com')->where('status', 'pending')->count())->toBe(1);
});

it('does NOT queue a transient (4.x.x) bounce — it self-heals', function () {
    expect(app(BounceParser::class)->reviewCandidate(reviewDsn('temp@example.com', '4.2.2')))->toBeNull();
});

it('queues an ARF complaint for review', function () {
    reviewMailbox([reviewArf('carol@example.com')]);

    app(DeliverabilityManager::class)->ingestAvailable();

    expect(BounceReview::where('candidate_email', 'carol@example.com')->where('event_type', 'complaint')->count())->toBe(1);
});

it('does NOT queue a review while VERP IS enabled (forged-bounce flood guard)', function () {
    config([
        'novfora.deliverability.verp.enabled' => true,
        'novfora.deliverability.verp.domain' => 'bounce.example.com',
        'novfora.deliverability.verp.key' => 'a-test-verp-key',
    ]);

    expect(app(BounceParser::class)->reviewCandidate(reviewDsn('victim@example.com', '5.0.0')))->toBeNull();
});

it('dedupes an identical re-polled message to a single review row', function () {
    reviewMailbox([reviewDsn('dup@example.com', '5.0.0'), reviewDsn('dup@example.com', '5.0.0')]);

    app(DeliverabilityManager::class)->ingestAvailable();

    expect(BounceReview::where('candidate_email', 'dup@example.com')->count())->toBe(1);
});

it('returns null for a non-bounce / garbage message (total)', function () {
    expect(app(BounceParser::class)->reviewCandidate('hello, not a bounce'))->toBeNull()
        ->and(app(BounceParser::class)->reviewCandidate(''))->toBeNull();
});

it('lets a staff member suppress a queued review by hand (the authentication)', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    $review = BounceReview::create([
        'candidate_email' => 'review@example.com', 'event_type' => 'bounce', 'permanent' => true,
        'excerpt' => 'Status: 5.0.0', 'dedupe_key' => hash('sha256', 'review@example.com|bounce|x'),
        'status' => 'pending', 'created_at' => now(),
    ]);

    $this->get(route('admin.system.suppressions'))->assertOk()->assertSee('review@example.com');

    Livewire::test('admin.bounce-reviews')->call('suppress', $review->id)->assertHasNoErrors();

    expect(EmailSuppression::where('email', 'review@example.com')->where('reason', 'bounce')->exists())->toBeTrue()
        ->and(BounceReview::find($review->id)->status)->toBe('resolved');
});

it('lets a staff member dismiss a queued review without suppressing', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    $review = BounceReview::create([
        'candidate_email' => 'keep@example.com', 'event_type' => 'bounce', 'permanent' => true,
        'excerpt' => 'Status: 5.0.0', 'dedupe_key' => hash('sha256', 'keep@example.com|bounce|y'),
        'status' => 'pending', 'created_at' => now(),
    ]);

    Livewire::test('admin.bounce-reviews')->call('dismiss', $review->id);

    expect(EmailSuppression::where('email', 'keep@example.com')->exists())->toBeFalse()
        ->and(BounceReview::find($review->id)->status)->toBe('dismissed');
});
