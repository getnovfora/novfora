<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// OAuth flow hardening (Phase 4 · M2.3): exercises the REAL Socialite drivers (no mock) so we assert the
// actual authorize URL carries the CSRF `state` nonce and — for providers that support it — PKCE (S256).

use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function hardenEnable(string $provider): void
{
    $s = app(Settings::class);
    $s->set("oauth.{$provider}.enabled", true);
    $s->set("oauth.{$provider}.client_id", 'real-client-id');
    $s->set("oauth.{$provider}.client_secret", 'real-client-secret');
}

function authorizeUrl(object $test, string $provider): string
{
    $response = $test->get(route('oauth.redirect', $provider));
    $response->assertRedirect();

    return (string) $response->headers->get('Location');
}

it('always includes the CSRF state nonce in the authorize URL', function () {
    hardenEnable('google');

    expect(authorizeUrl($this, 'google'))->toContain('state=');
});

it('enables PKCE (S256) for providers that support it', function () {
    hardenEnable('google');
    $url = authorizeUrl($this, 'google');

    expect($url)->toContain('code_challenge=');
    expect($url)->toContain('code_challenge_method=S256');
});

it('does not send PKCE to GitHub (OAuth Apps do not support it; state still protects)', function () {
    hardenEnable('github');
    $url = authorizeUrl($this, 'github');

    expect($url)->toContain('state=');
    expect($url)->not->toContain('code_challenge=');
});

it('resolves and PKCE-protects the Discord extension provider', function () {
    hardenEnable('discord');
    $url = authorizeUrl($this, 'discord');

    expect($url)->toContain('discord.com');
    expect($url)->toContain('code_challenge=');
});

it('exposes the OAuth round-trip only over GET (state-protected), not a CSRF-able POST', function () {
    hardenEnable('google');

    // The redirect + callback are GET (protected by the state nonce), not endpoints that mutate on a forged POST.
    $this->post(route('oauth.redirect', 'google'))->assertStatus(405);
});
