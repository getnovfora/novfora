<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\ExternalSignalPolicy;
use App\AntiSpam\RegistrationGuard;
use App\AntiSpam\ScreeningResult;
use App\AntiSpam\SpamReporter;
use App\Models\Setting;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Phase 4 · M6.3 — external-signal tuning + the privacy fence. The block threshold is admin-tunable; post
| CONTENT is sent to a third party ONLY with an explicit opt-in (default off). Reporting is inert without a key.
| NOT validated against the live StopForumSpam submission API (mocked HTTP).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

// ── The privacy policy ───────────────────────────────────────────────────────────────────────────────────

it('never permits sending content to third parties by default (the fence)', function () {
    expect(app(ExternalSignalPolicy::class)->maySubmitContent())->toBeFalse();
});

it('exposes the admin-tuned confidence threshold (default 75)', function () {
    $policy = app(ExternalSignalPolicy::class);
    expect($policy->confidenceThreshold())->toBe(75);

    app(Settings::class)->set('antispam.sfs_confidence_threshold', '60');
    expect($policy->confidenceThreshold())->toBe(60);
});

// ── RegistrationGuard honours the tuned threshold ────────────────────────────────────────────────────────

it('uses the admin-tuned threshold to decide block vs flag', function () {
    config([
        'novfora.antispam.registration.stopforumspam.use_api' => true,
        'novfora.antispam.registration.stopforumspam.enabled' => true,
    ]);
    // Fake the StopForumSpam live API to report the registrant at confidence 70.
    Http::fake(['*stopforumspam.org*' => Http::response(['success' => 1, 'email' => ['appears' => 1, 'confidence' => 70], 'ip' => ['appears' => 0], 'username' => ['appears' => 0]], 200)]);

    // Default threshold 75 → confidence 70 < 75 → FLAG (don't block).
    $a = app(RegistrationGuard::class)->screen(['email' => 'a@flag.test', 'username' => 'u1', 'ip' => '1.2.3.4']);
    expect($a->decision)->toBe(ScreeningResult::FLAG);

    // Tune down to 60 → 70 ≥ 60 → BLOCK.
    app(Settings::class)->set('antispam.sfs_confidence_threshold', '60');
    $b = app(RegistrationGuard::class)->screen(['email' => 'b@block.test', 'username' => 'u2', 'ip' => '5.6.7.8']);
    expect($b->decision)->toBe(ScreeningResult::BLOCK);
});

// ── The reporter: inert without opt-in/key; content only with the content opt-in ────────────────────────

it('makes no external call when no submission key is set', function () {
    Http::fake();

    $sent = app(SpamReporter::class)->reportSpammer('1.2.3.4', 'spam@x.test', 'spammer', 'buy now');

    expect($sent)->toBeFalse();
    Http::assertNothingSent();
});

it('makes no external call when the live API is disabled', function () {
    Http::fake();
    $s = app(Settings::class);
    $s->set('antispam.sfs_api_key', 'key123');
    $s->set('antispam.sfs_use_api', false);

    $sent = app(SpamReporter::class)->reportSpammer('1.2.3.4', 'spam@x.test', 'spammer', 'buy now');

    expect($sent)->toBeFalse();
    Http::assertNothingSent();
});

it('submits metadata only (never post content) without the content opt-in', function () {
    Http::fake(['*stopforumspam*' => Http::response('ok', 200)]);
    $s = app(Settings::class);
    $s->set('antispam.sfs_use_api', true);
    $s->set('antispam.sfs_api_key', 'key123');

    $sent = app(SpamReporter::class)->reportSpammer('1.2.3.4', 'spam@x.test', 'spammer', 'secret member post body');

    expect($sent)->toBeTrue();
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'stopforumspam.com')
            && ($request['ip'] ?? null) === '1.2.3.4'
            && ($request['username'] ?? null) === 'spammer'
            && ! isset($request['evidence']); // NO post content on the wire
    });
});

it('includes post content as evidence only with the explicit content opt-in', function () {
    Http::fake(['*stopforumspam*' => Http::response('ok', 200)]);
    $s = app(Settings::class);
    $s->set('antispam.sfs_use_api', true);
    $s->set('antispam.sfs_api_key', 'key123');
    $s->set('antispam.external_content_optin', true);

    app(SpamReporter::class)->reportSpammer('1.2.3.4', 'spam@x.test', 'spammer', 'the offending post body');

    Http::assertSent(fn ($request) => ($request['evidence'] ?? null) === 'the offending post body');
});

// ── Admin settings ───────────────────────────────────────────────────────────────────────────────────────

it('saves the external-signal settings (threshold, content opt-in, encrypted key)', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins'], ['email' => 'sfs-admin@acp.test']));

    Livewire::actingAs($admin)
        ->test('admin.settings.antispam')
        ->set('captchaProvider', 'qa')
        ->set('sfsUseApi', true)
        ->set('sfsThreshold', 60)
        ->set('externalContentOptIn', true)
        ->set('sfsApiKey', 'sfskey123')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(Settings::class);
    expect($settings->int('antispam.sfs_confidence_threshold'))->toBe(60);
    expect($settings->bool('antispam.external_content_optin'))->toBeTrue();
    expect($settings->secretIsSet('antispam.sfs_api_key'))->toBeTrue();

    $raw = Setting::where('key', 'antispam.sfs_api_key')->value('value');
    expect($raw)->not->toBe('sfskey123'); // encrypted at rest

    // Sanity: the User import is exercised so the file's strict typing stays honest.
    expect($admin)->toBeInstanceOf(User::class);
});
