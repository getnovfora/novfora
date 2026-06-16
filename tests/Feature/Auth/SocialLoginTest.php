<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Ban;
use App\Models\RegistrationCheck;
use App\Models\SocialAccount;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** Configure a provider so SocialProviders::isAvailable() is true. */
function enableProvider(string $provider = 'google'): void
{
    $s = app(Settings::class);
    $s->set("oauth.{$provider}.enabled", true);
    $s->set("oauth.{$provider}.client_id", 'client-id');
    $s->set("oauth.{$provider}.client_secret", 'client-secret');
}

function fakeSocialUser(string $id, string $email, string $nick = 'handle'): SocialiteUser
{
    $u = new SocialiteUser;
    $u->id = $id;
    $u->email = $email;
    $u->name = 'Test Person';
    $u->nickname = $nick;
    $u->avatar = 'https://cdn.example.com/a.png';

    return $u;
}

/** Mock Socialite to return a driver whose ->user() yields the given fake user. */
function mockSocialiteReturning(SocialiteUser $user): void
{
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('scopes')->andReturnSelf();
    $driver->shouldReceive('enablePKCE')->andReturnSelf();
    $driver->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->andReturn($driver);
}

// ── New user via provider ────────────────────────────────────────────────────────────────────────────────

it('creates a new account for a first-time provider sign-in', function () {
    enableProvider('google');
    mockSocialiteReturning(fakeSocialUser('g-1001', 'newcomer@oauth.test', 'newcomer'));

    $this->get(route('oauth.callback', 'google'))->assertRedirect(route('home'));

    $this->assertAuthenticated();
    $user = User::where('email', 'newcomer@oauth.test')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull();
    expect(SocialAccount::where('provider', 'google')->where('provider_user_id', 'g-1001')->where('user_id', $user->id)->exists())->toBeTrue();
    expect($user->groups->pluck('slug'))->toContain('members');
});

// ── Existing social identity → same account ──────────────────────────────────────────────────────────────

it('signs the same user in for a returning provider identity (no duplicate)', function () {
    enableProvider('github');
    $user = User::factory()->create(['email' => 'returning@oauth.test']);
    SocialAccount::create(['user_id' => $user->id, 'provider' => 'github', 'provider_user_id' => 'gh-42', 'linked_at' => now()]);
    mockSocialiteReturning(fakeSocialUser('gh-42', 'returning@oauth.test'));

    $this->get(route('oauth.callback', 'github'))->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
    expect(User::where('email', 'returning@oauth.test')->count())->toBe(1); // no duplicate created
    expect(SocialAccount::where('provider', 'github')->count())->toBe(1);
});

// ── Email collision — NEVER silent-merge (APEX) ──────────────────────────────────────────────────────────

it('refuses to sign in when the provider email collides with an existing account', function () {
    enableProvider('google');
    $local = User::factory()->create(['email' => 'taken@oauth.test']);
    mockSocialiteReturning(fakeSocialUser('g-9999', 'taken@oauth.test'));

    $this->get(route('oauth.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
    // No identity was attached to the existing account, and no duplicate user was created.
    expect(SocialAccount::where('user_id', $local->id)->exists())->toBeFalse();
    expect(User::where('email', 'taken@oauth.test')->count())->toBe(1);
});

// ── Disabled / unknown provider ──────────────────────────────────────────────────────────────────────────

it('404s a disabled provider', function () {
    // google not enabled
    $this->get(route('oauth.redirect', 'google'))->assertNotFound();
    $this->get(route('oauth.callback', 'google'))->assertNotFound();
});

it('404s an unknown provider', function () {
    $this->get(route('oauth.redirect', 'myspace'))->assertNotFound();
});

// ── Redirect kicks off the dance ─────────────────────────────────────────────────────────────────────────

it('redirects an enabled provider to its consent screen', function () {
    enableProvider('google');
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('scopes')->andReturnSelf();
    $driver->shouldReceive('enablePKCE')->andReturnSelf();
    $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.example.com/o/oauth2/auth'));
    Socialite::shouldReceive('driver')->andReturn($driver);

    $this->get(route('oauth.redirect', 'google'))->assertRedirect('https://accounts.example.com/o/oauth2/auth');
});

// ── State / CSRF failure fails closed ────────────────────────────────────────────────────────────────────

it('fails closed on an invalid OAuth state', function () {
    enableProvider('google');
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('scopes')->andReturnSelf();
    $driver->shouldReceive('enablePKCE')->andReturnSelf();
    $driver->shouldReceive('user')->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->andReturn($driver);

    $this->get(route('oauth.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
});

// ── Provider declined ────────────────────────────────────────────────────────────────────────────────────

it('handles a declined consent gracefully', function () {
    enableProvider('google');

    $this->get(route('oauth.callback', 'google', ['error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
});

// ── P5.1: SSO must not bypass mandatory staff 2FA ─────────────────────────────────────────────────────────

it('steps a staff member with 2FA up to the TOTP challenge after SSO instead of granting a session', function () {
    enableProvider('google');
    $staff = Users::inGroups(['members', 'admins'], ['email' => 'staff2fa@oauth.test']);
    Users::withTwoFactor($staff);
    SocialAccount::create(['user_id' => $staff->id, 'provider' => 'google', 'provider_user_id' => 'g-staff', 'linked_at' => now()]);
    mockSocialiteReturning(fakeSocialUser('g-staff', 'staff2fa@oauth.test'));

    $this->get(route('oauth.callback', 'google'))->assertRedirect(route('two-factor.login'));

    $this->assertGuest();                          // NO privileged session until the second factor is verified
    expect(session('login.id'))->toBe($staff->id); // handed off to Fortify's existing TOTP challenge
});

it('signs a staff member WITHOUT a confirmed authenticator in normally after SSO (RequireTwoFactorForStaff then forces setup)', function () {
    enableProvider('google');
    $staff = Users::inGroups(['members', 'moderators'], ['email' => 'staffno2fa@oauth.test']);
    SocialAccount::create(['user_id' => $staff->id, 'provider' => 'google', 'provider_user_id' => 'g-staff2', 'linked_at' => now()]);
    mockSocialiteReturning(fakeSocialUser('g-staff2', 'staffno2fa@oauth.test'));

    $this->get(route('oauth.callback', 'google'))->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($staff->fresh());
});

// ── P5.1: OAuth just-in-time provisioning honours the registration gates ──────────────────────────────────

it('refuses OAuth signup when registration is closed', function () {
    enableProvider('google');
    app(Settings::class)->set('registration.enabled', false);
    mockSocialiteReturning(fakeSocialUser('g-closed', 'closed@oauth.test'));

    $this->get(route('oauth.callback', 'google'))->assertRedirect(route('login'))->assertSessionHas('error');

    $this->assertGuest();
    expect(User::where('email', 'closed@oauth.test')->exists())->toBeFalse();
});

it('refuses OAuth signup for a banned email (an email ban cannot be evaded via SSO)', function () {
    enableProvider('google');
    Ban::create(['type' => 'email', 'value' => 'banned@oauth.test', 'scope_type' => 'global']);
    mockSocialiteReturning(fakeSocialUser('g-banned', 'banned@oauth.test'));

    $this->get(route('oauth.callback', 'google'))->assertRedirect(route('login'))->assertSessionHas('error');

    $this->assertGuest();
    expect(User::where('email', 'banned@oauth.test')->exists())->toBeFalse();
});

it('routes a flagged OAuth signup into the moderation queue as pending', function () {
    enableProvider('google');
    // Force a FLAG (not a block): exceed the per-IP velocity so the screener flags → status=pending.
    config(['novfora.antispam.registration.velocity.per_ip_per_hour' => 1]);
    RegistrationCheck::create(['ip_address' => '127.0.0.1', 'decision' => 'allow', 'created_at' => now()]);
    mockSocialiteReturning(fakeSocialUser('g-flagged', 'flagged@oauth.test'));

    $this->get(route('oauth.callback', 'google'))->assertRedirect(route('home'));

    $user = User::where('email', 'flagged@oauth.test')->firstOrFail();
    expect($user->status)->toBe('pending'); // created, but held for moderation — not silently active
});
