<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Deliverability\DeliverabilityManager;
use App\Deliverability\Digest\DigestAssembler;
use App\Deliverability\Suppressor;
use App\Deliverability\Verp;
use App\Mail\DigestMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Deliverability;

/*
| GO CRITERION 4 — graceful absence (mirrors the M4 service-tier forced-absence suite). With NO provider,
| NO webhook, NO VERP key and the imap extension absent (→ NullBounceMailbox), the pipeline still sends
| best-effort baseline mail, still honours the suppression list, and degrades to the VERP/manual floor —
| NEVER an error.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

it('ingests cleanly with nothing configured — returns 0, never throws, floor = manual', function () {
    config(['novfora.deliverability.enabled' => true]); // enabled, but no webhook/verp/imap

    $manager = app(DeliverabilityManager::class);

    expect($manager->ingestAvailable())->toBe(0)
        ->and($manager->activePath())->toBe('manual');
});

it('still assembles a best-effort digest with no provider configured', function () {
    $user = Deliverability::user('daily');
    Deliverability::stage($user, 2);

    app(DigestAssembler::class)->tick();

    Mail::assertSent(DigestMail::class, 1);
});

it('treats VERP as a no-op when unconfigured — no Return-Path, no error', function () {
    expect(app(Verp::class)->enabled())->toBeFalse()
        ->and(app(Verp::class)->returnPathFor(1, 2))->toBeNull()
        ->and(app(Verp::class)->decode('bounce+1.2.whatever@example.com'))->toBeNull();
});

it('keeps the manual suppression floor working with no provider, and the gate honours it', function () {
    $user = Deliverability::user('daily');
    Deliverability::stage($user, 1);

    app(Suppressor::class)->suppress($user->email, 'manual');
    app(DigestAssembler::class)->tick();

    Mail::assertNotSent(DigestMail::class); // suppressed → skipped even with no provider configured
});
