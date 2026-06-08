<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Deliverability\Digest\DigestAssembler;
use App\Deliverability\Digest\DigestQueue;
use App\Deliverability\SuppressionGate;
use App\Jobs\SendDigestJob;
use App\Mail\DigestMail;
use App\Models\DigestPreference;
use App\Models\DigestQueueItem;
use App\Models\DigestRun;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Support\Deliverability;

/*
| GO CRITERION 5 — preference + 1-click unsubscribe honoured at send time: no mail to an opted-out or
| suppressed user. The send gate is re-checked at BOTH assembly and inside the send job, so an opt-out /
| suppression that lands AFTER enqueue is still honoured.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

it('honours 1-click unsubscribe (signed) at send time', function () {
    $user = Deliverability::user('daily');
    Deliverability::stage($user, 2);

    $this->get(URL::signedRoute('deliverability.unsubscribe', ['user' => $user->getKey()]))->assertOk();
    expect(DigestPreference::where('user_id', $user->getKey())->value('cadence'))->toBe('off');

    app(DigestAssembler::class)->tick();
    Mail::assertNotSent(DigestMail::class);
    // Gated → the period is RETIRED (a terminal, item-0 run; items claimed) so the user is not re-scanned
    // every tick forever and their items don't leak — but no mail is ever sent.
    expect(DigestRun::where('user_id', $user->getKey())->where('status', 'sent')->where('item_count', 0)->count())->toBe(1)
        ->and(DigestQueueItem::whereNull('digest_run_id')->count())->toBe(0);
});

it('rejects an unsigned / forged unsubscribe link', function () {
    $user = Deliverability::user('daily');

    $this->get(route('deliverability.unsubscribe', ['user' => $user->getKey()]))->assertForbidden();
    expect(DigestPreference::where('user_id', $user->getKey())->value('cadence'))->toBe('daily');
});

it('skips a suppressed address entirely', function () {
    $user = Deliverability::user('daily');
    EmailSuppression::create(['email' => $user->email, 'reason' => 'bounce', 'created_at' => now()]);
    Deliverability::stage($user, 2);

    app(DigestAssembler::class)->tick();
    Mail::assertNotSent(DigestMail::class);
});

it('re-checks the gate inside the send job — a suppression after enqueue still suppresses', function () {
    $user = Deliverability::user('daily');
    Deliverability::stage($user, 1);

    // A built run already enqueued (mailed_at set, items claimed) — i.e. between enqueue and the cron drain.
    $run = DigestRun::create([
        'user_id' => $user->getKey(), 'cadence' => 'daily', 'period_key' => \App\Deliverability\Digest\PeriodKey::for('daily'),
        'status' => 'built', 'built_at' => now(), 'item_count' => 1, 'mailed_at' => now(),
    ]);
    DigestQueueItem::where('user_id', $user->getKey())->update(['digest_run_id' => $run->getKey()]);

    EmailSuppression::create(['email' => $user->email, 'reason' => 'complaint', 'created_at' => now()]);

    (new SendDigestJob((int) $run->getKey()))->handle(app(SuppressionGate::class));

    Mail::assertNotSent(DigestMail::class);
    expect($run->fresh()->status)->toBe('sent'); // period consumed, no mail
});

it('only stages batched-cadence users into the digest path (immediate users keep the live path)', function () {
    $daily = Deliverability::user('daily');
    $immediate = Deliverability::user('immediate');
    $queue = app(DigestQueue::class);

    expect($queue->enqueue($daily, 'reply', null, ['topic_title' => 'T']))->not->toBeNull()
        ->and($queue->enqueue($immediate, 'reply', null, ['topic_title' => 'T']))->toBeNull();
    expect(DigestQueueItem::count())->toBe(1);
});

it('does not double-stage the same source notification', function () {
    $user = Deliverability::user('daily');
    $queue = app(DigestQueue::class);
    $nid = (string) \Illuminate\Support\Str::uuid();

    $queue->enqueue($user, 'reply', null, ['topic_title' => 'T'], $nid);
    $queue->enqueue($user, 'reply', null, ['topic_title' => 'T'], $nid);

    expect(DigestQueueItem::where('user_id', $user->getKey())->count())->toBe(1);
});

afterEach(fn () => Carbon::setTestNow());
