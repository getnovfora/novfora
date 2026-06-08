<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Deliverability\SuppressionGate;
use App\Deliverability\Suppressor;
use App\Mail\NotificationMail;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/*
| Reviewer finding (real) — email casing. The suppression list is stored lower-cased, so EVERY lookup must be
| case-insensitive: a bounce on Alice@Example.com must suppress alice@example.com, and the LIVE mail path
| (App\Notifications\Notifier) must skip a mixed-case recipient whose lower-cased address is suppressed.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

it('stores a suppression lower-cased and matches it case-insensitively (store + gate)', function () {
    expect(app(Suppressor::class)->suppress('Alice@Example.com', 'bounce'))->toBeTrue();
    expect(EmailSuppression::where('email', 'alice@example.com')->exists())->toBeTrue();

    $gate = app(SuppressionGate::class);
    expect($gate->suppressed('alice@example.com'))->toBeTrue()
        ->and($gate->suppressed('ALICE@EXAMPLE.COM'))->toBeTrue()
        ->and($gate->suppressed('Alice@Example.com'))->toBeTrue();
});

it('skips mail to a mixed-case recipient whose lower-cased address is suppressed (live Notifier path)', function () {
    // A bounce was recorded for the canonical lower-case address.
    app(Suppressor::class)->suppress('Alice@Example.com', 'bounce');

    // The live immediate path must NOT mail the mixed-case recipient (the bug), but still notify in-app.
    $recipient = User::factory()->create(['email' => 'Alice@Example.com']);
    $actor = User::factory()->create();

    app(Notifier::class)->send($recipient, 'reply', $actor, ['topic_title' => 'T', 'thread_id' => 1]);

    expect($recipient->notifications()->count())->toBe(1); // in-app unaffected
    Mail::assertNotQueued(NotificationMail::class);          // no mail to a suppressed (bounced) address
});
