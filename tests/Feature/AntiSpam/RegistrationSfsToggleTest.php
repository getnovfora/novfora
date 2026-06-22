<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\RegistrationGuard;
use App\AntiSpam\ScreeningResult;
use App\Models\BlocklistEntry;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
| Batch 2026-06-21 · Branch 4 — the operator's StopForumSpam live-API toggle (antispam.sfs_use_api, via
| ExternalSignalPolicy::apiEnabled) now ACTUALLY controls the live call, while the fail-safe (disposable / ban /
| cached-blocklist checks + degrade-FLAG) is preserved so turning the API off never silently ALLOWs spam.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('with the live API OFF: no live StopForumSpam call, but disposable + cached checks still run', function () {
    Http::fake();
    config(['novfora.antispam.registration.stopforumspam.enabled' => true]);
    app(Settings::class)->set('antispam.sfs_use_api', false);

    // A disposable domain still BLOCKS (local signal, no live call).
    BlocklistEntry::create(['source' => 'disposable', 'type' => 'email_domain', 'value' => 'throwaway.test', 'confidence' => 100]);
    $d = app(RegistrationGuard::class)->screen(['email' => 'a@throwaway.test', 'username' => 'u', 'ip' => '1.1.1.1']);
    expect($d->decision)->toBe(ScreeningResult::BLOCK);

    // A cron-cached StopForumSpam listing still BLOCKs (cache check, no live call).
    BlocklistEntry::create(['source' => 'stopforumspam', 'type' => 'email', 'value' => 'spam@bad.test', 'confidence' => 90, 'expires_at' => now()->addDays(7)]);
    $c = app(RegistrationGuard::class)->screen(['email' => 'spam@bad.test', 'username' => 'u', 'ip' => '2.2.2.2']);
    expect($c->decision)->toBe(ScreeningResult::BLOCK)
        ->and($c->reasons)->toContain('stopforumspam');

    Http::assertNothingSent(); // the operator turned the live API off → never a live request
});

it('with the live API ON: the live StopForumSpam call is exercised', function () {
    config(['novfora.antispam.registration.stopforumspam.enabled' => true]);
    app(Settings::class)->set('antispam.sfs_use_api', true);
    Http::fake(['*stopforumspam.org*' => Http::response([
        'success' => 1, 'email' => ['appears' => 1, 'confidence' => 90], 'ip' => ['appears' => 0], 'username' => ['appears' => 0],
    ], 200)]);

    $r = app(RegistrationGuard::class)->screen(['email' => 'spam@bad.test', 'username' => 'u', 'ip' => '3.3.3.3']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'stopforumspam.org'));
    expect($r->decision)->toBe(ScreeningResult::BLOCK); // confidence 90 ≥ the default threshold 75
});

it('the operator sfs_use_api setting overrides the config flag (the ACP toggle is now authoritative)', function () {
    config([
        'novfora.antispam.registration.stopforumspam.enabled' => true,
        'novfora.antispam.registration.stopforumspam.use_api' => true, // config says the live API is ON…
    ]);
    app(Settings::class)->set('antispam.sfs_use_api', false); // …but the operator turned it OFF in the ACP.
    Http::fake();

    app(RegistrationGuard::class)->screen(['email' => 'x@y.test', 'username' => 'u', 'ip' => '4.4.4.4']);

    Http::assertNothingSent(); // the DB setting wins → no live call (previously the raw config did → inert toggle)
});
