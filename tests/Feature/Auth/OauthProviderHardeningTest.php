<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Auth\Social\SocialProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;

/*
| Batch 2026-06-21 · Branch 4 — OAuth hardening guards: the redirect route stays rate-limited (item 1) and the
| Discord Socialite driver resolves (item 3, i.e. SocialiteServiceProvider is registered in bootstrap/providers).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('keeps the OAuth redirect route behind a rate limiter (item 1)', function () {
    $route = app('router')->getRoutes()->getByName('oauth.redirect');

    expect($route)->not->toBeNull();
    expect(collect($route->gatherMiddleware())->contains(fn ($m): bool => str_contains((string) $m, 'throttle')))->toBeTrue();
});

it('resolves the Discord Socialite driver — SocialiteServiceProvider is registered (item 3)', function () {
    // driver() configures services.discord from settings (empty here) and resolves via Socialite::driver().
    // If SocialiteServiceProvider's Socialite::extend('discord', …) were missing, this would throw.
    $driver = app(SocialProviders::class)->driver('discord');

    expect($driver)->toBeInstanceOf(Provider::class);
});
