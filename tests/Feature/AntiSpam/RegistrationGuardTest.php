<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\RegistrationGuard;
use App\Models\Ban;
use App\Models\BlocklistEntry;
use App\Models\RegistrationCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
| Registration screening (ADR-0007 §2.2): the tri-state allow/flag/block decision, flag-don't-block on
| uncertainty, and — the non-negotiable tier-graceful requirement — degrade (never error) when the live
| StopForumSpam API is unreachable.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // The test env defaults the live API off (no network); these tests fake Http, so opt it back on.
    config(['novfora.antispam.registration.stopforumspam.use_api' => true]);
    $this->seed();
});

function guard(): RegistrationGuard
{
    return app(RegistrationGuard::class);
}

/** A "clean" StopForumSpam response (success, nothing listed). */
function sfsClean(): void
{
    Http::fake(['api.stopforumspam.org/*' => Http::response(['success' => 1], 200)]);
}

it('allows a clean registration and records the check', function () {
    sfsClean();

    $result = guard()->screen(['email' => 'real@example.com', 'username' => 'real', 'ip' => '203.0.113.10']);

    expect($result->allowed())->toBeTrue();
    expect(RegistrationCheck::where('email', 'real@example.com')->where('decision', 'allow')->exists())->toBeTrue();
});

it('blocks a high-confidence StopForumSpam listing', function () {
    Http::fake(['api.stopforumspam.org/*' => Http::response(['success' => 1, 'ip' => ['appears' => 1, 'confidence' => 95]], 200)]);

    $result = guard()->screen(['email' => 'spammer@example.com', 'username' => 'spam', 'ip' => '203.0.113.66']);

    expect($result->blocked())->toBeTrue();
    expect($result->reasons)->toContain('stopforumspam');
});

it('flags (does not block) a borderline StopForumSpam listing', function () {
    Http::fake(['api.stopforumspam.org/*' => Http::response(['success' => 1, 'email' => ['appears' => 1, 'confidence' => 40]], 200)]);

    $result = guard()->screen(['email' => 'maybe@example.com', 'username' => 'maybe', 'ip' => '203.0.113.7']);

    expect($result->flagged())->toBeTrue();
});

it('degrades to the cached blocklist when the API is down — never errors', function () {
    BlocklistEntry::create(['type' => 'ip', 'value' => '203.0.113.99', 'source' => 'stopforumspam', 'confidence' => 90, 'expires_at' => now()->addDay()]);
    Http::fake(['api.stopforumspam.org/*' => Http::response('', 503)]);

    $result = guard()->screen(['email' => 'x@example.com', 'username' => 'x', 'ip' => '203.0.113.99']);

    expect($result->degraded)->toBeTrue();
    expect($result->blocked())->toBeTrue(); // cached confidence 90 ≥ threshold 75
});

it('FLAGS (never silently allows) when the API is down and nothing is cached — fail safe, not open', function () {
    Http::fake(['api.stopforumspam.org/*' => Http::response('', 503)]);

    $result = guard()->screen(['email' => 'newcomer@example.com', 'username' => 'newcomer', 'ip' => '203.0.113.5']);

    // Phase-1.5 F-C: a degraded check holds the account for moderation (pending) instead of failing open.
    // Still flag-don't-block — the account is created and recoverable, never hard-blocked on a mere degrade.
    expect($result->flagged())->toBeTrue();
    expect($result->blocked())->toBeFalse();
    expect($result->degraded)->toBeTrue();
    expect($result->reasons)->toContain('stopforumspam_degraded');
});

it('flags a cron-warmed StopForumSpam toxic email domain (flag-don\'t-block)', function () {
    sfsClean();
    BlocklistEntry::create(['type' => 'email_domain', 'value' => 'spammer.example', 'source' => 'stopforumspam', 'confidence' => 100, 'expires_at' => now()->addDay()]);

    $result = guard()->screen(['email' => 'x@spammer.example', 'username' => 'x', 'ip' => '203.0.113.42']);

    expect($result->flagged())->toBeTrue();
    expect($result->reasons)->toContain('toxic_domain');
});

it('warms the blocklist cache from the downloaded toxic-domains list', function () {
    config(['novfora.antispam.registration.stopforumspam.warm.enabled' => true]);
    Http::fake(['*' => Http::response("# a comment line\nbad-one.example\nbad-two.example\n", 200)]);

    $this->artisan('novfora:antispam:warm')->assertSuccessful();

    expect(BlocklistEntry::where('source', 'stopforumspam')->where('type', 'email_domain')->where('value', 'bad-one.example')->exists())->toBeTrue();
    expect(BlocklistEntry::where('value', 'bad-two.example')->exists())->toBeTrue();
});

it('blocks a banned IP', function () {
    sfsClean();
    Ban::create(['type' => 'ip', 'value' => '203.0.113.13', 'scope_type' => 'global']);

    $result = guard()->screen(['email' => 'a@example.com', 'username' => 'a', 'ip' => '203.0.113.13']);

    expect($result->blocked())->toBeTrue();
    expect($result->reasons)->toContain('banned');
});

it('blocks a disposable email address (seeded local list)', function () {
    sfsClean();

    $result = guard()->screen(['email' => 'throwaway@mailinator.com', 'username' => 'tw', 'ip' => '203.0.113.21']);

    expect($result->blocked())->toBeTrue();
    expect($result->reasons)->toContain('disposable_email');
});

it('flags on IP registration velocity', function () {
    sfsClean();
    for ($i = 0; $i < 5; $i++) { // per_ip_per_hour default = 5
        RegistrationCheck::create(['ip_address' => '203.0.113.200', 'decision' => 'allow', 'created_at' => now()->subMinutes(5)]);
    }

    $result = guard()->screen(['email' => 'sixth@example.com', 'username' => 'sixth', 'ip' => '203.0.113.200']);

    expect($result->flagged())->toBeTrue();
    expect($result->reasons)->toContain('velocity');
});

it('purges registration checks past the retention window (GDPR)', function () {
    config(['novfora.antispam.retention.registration_checks_days' => 30]);
    RegistrationCheck::create(['email' => 'old@example.com', 'decision' => 'allow', 'created_at' => now()->subDays(40)]);
    RegistrationCheck::create(['email' => 'recent@example.com', 'decision' => 'allow', 'created_at' => now()->subDays(5)]);

    $this->artisan('novfora:antispam:purge')->assertSuccessful();

    expect(RegistrationCheck::where('email', 'old@example.com')->exists())->toBeFalse();
    expect(RegistrationCheck::where('email', 'recent@example.com')->exists())->toBeTrue();
});
