<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function lkEnable(string $provider = 'google'): void
{
    $s = app(Settings::class);
    $s->set("oauth.{$provider}.enabled", true);
    $s->set("oauth.{$provider}.client_id", 'cid');
    $s->set("oauth.{$provider}.client_secret", 'secret');
}

function lkUser(string $id, string $email, string $nick = 'handle'): SocialiteUser
{
    $u = new SocialiteUser;
    $u->id = $id;
    $u->email = $email;
    $u->name = 'Linker';
    $u->nickname = $nick;
    $u->avatar = 'https://cdn.example.com/a.png';

    return $u;
}

function lkMock(SocialiteUser $user): void
{
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('scopes')->andReturnSelf();
    $driver->shouldReceive('enablePKCE')->andReturnSelf();
    $driver->shouldReceive('user')->andReturn($user);
    $driver->shouldReceive('redirect')->andReturn(redirect('https://provider.test/auth'));
    Socialite::shouldReceive('driver')->andReturn($driver);
}

// ── Start a link ─────────────────────────────────────────────────────────────────────────────────────────

it('starts a link by flagging the session and redirecting to the provider', function () {
    lkEnable('google');
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'linker@oauth.test']);
    lkMock(lkUser('g-1', 'whatever@oauth.test'));

    $this->actingAs($user)
        ->post(route('oauth.link', 'google'))
        ->assertRedirect('https://provider.test/auth')
        ->assertSessionHas('oauth.link_intent', 'google');
});

it('404s a link start for a disabled provider', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'nope@oauth.test']);

    $this->actingAs($user)->post(route('oauth.link', 'google'))->assertNotFound();
});

// ── Complete a link ──────────────────────────────────────────────────────────────────────────────────────

it('links a provider identity to the authenticated account', function () {
    lkEnable('github');
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'me@oauth.test']);
    lkMock(lkUser('gh-7', 'me-at-github@oauth.test', 'octocat'));

    $this->actingAs($user)
        ->withSession(['oauth.link_intent' => 'github'])
        ->get(route('oauth.callback', 'github'))
        ->assertRedirect(route('settings.linked-accounts'))
        ->assertSessionHas('status');

    expect(SocialAccount::where('user_id', $user->id)->where('provider', 'github')->where('provider_user_id', 'gh-7')->exists())->toBeTrue();
});

it('refuses to link an identity already linked to a different account', function () {
    lkEnable('github');
    $other = User::factory()->create(['email' => 'other@oauth.test']);
    SocialAccount::create(['user_id' => $other->id, 'provider' => 'github', 'provider_user_id' => 'gh-dup', 'linked_at' => now()]);
    $me = Users::inGroups(['members', 'tl1'], ['email' => 'me2@oauth.test']);
    lkMock(lkUser('gh-dup', 'me2@oauth.test'));

    $this->actingAs($me)
        ->withSession(['oauth.link_intent' => 'github'])
        ->get(route('oauth.callback', 'github'))
        ->assertRedirect(route('settings.linked-accounts'))
        ->assertSessionHas('error');

    expect(SocialAccount::where('user_id', $me->id)->exists())->toBeFalse();
});

// ── Unlink ───────────────────────────────────────────────────────────────────────────────────────────────

it('unlinks a provider from the account', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'unlinker@oauth.test']);
    SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-x', 'linked_at' => now()]);

    $this->actingAs($user)
        ->delete(route('oauth.unlink', 'google'))
        ->assertRedirect(route('settings.linked-accounts'));

    expect(SocialAccount::where('user_id', $user->id)->where('provider', 'google')->exists())->toBeFalse();
});

// ── The full APEX flow: collision refuses login, but a signed-in user CAN link (proven control) ──────────

it('lets a user link the provider after a collision blocked auto-login (proven control)', function () {
    lkEnable('google');
    // A local password account whose email matches the provider identity.
    $local = Users::inGroups(['members', 'tl1'], ['email' => 'collide@oauth.test']);

    // 1) Logged-out: signing in with that provider identity is REFUSED (no silent merge).
    lkMock(lkUser('g-collide', 'collide@oauth.test'));
    $this->get(route('oauth.callback', 'google'))->assertRedirect(route('login'))->assertSessionHas('error');
    expect(SocialAccount::where('user_id', $local->id)->exists())->toBeFalse();

    // 2) Signed in with their PASSWORD (proven control), the SAME identity links successfully.
    $this->actingAs($local)
        ->withSession(['oauth.link_intent' => 'google'])
        ->get(route('oauth.callback', 'google'))
        ->assertRedirect(route('settings.linked-accounts'))
        ->assertSessionHas('status');

    expect(SocialAccount::where('user_id', $local->id)->where('provider', 'google')->where('provider_user_id', 'g-collide')->exists())->toBeTrue();
});

// ── The page renders ─────────────────────────────────────────────────────────────────────────────────────

it('shows the linked-accounts page to an authenticated user', function () {
    lkEnable('google');
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'page@oauth.test']);

    $this->actingAs($user)->get(route('settings.linked-accounts'))->assertOk()->assertSee('Google');
});
