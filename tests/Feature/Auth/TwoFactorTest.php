<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

uses(RefreshDatabase::class);

it('enables two-factor authentication (secret set, awaiting confirmation)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('two-factor.enable'))->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull(); // not active until a code is confirmed
});

it('confirms two-factor with a valid TOTP', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('two-factor.enable'));
    $user->refresh();

    $this->actingAs($user)->post(route('two-factor.confirm'), ['code' => Users::totp($user)])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('rejects an invalid confirmation code', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('two-factor.enable'));

    $this->actingAs($user)->post(route('two-factor.confirm'), ['code' => '000000'])
        ->assertSessionHasErrors();

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('challenges 2FA at login and completes with a valid code', function () {
    $user = Users::withTwoFactor(User::factory()->create(['email' => 'ada@hearth.test']));

    // Right credentials, but a 2FA user is not logged in yet — bounced to the challenge.
    $this->post('/login', ['email' => 'ada@hearth.test', 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    // A valid TOTP completes the login.
    $this->post(route('two-factor.login'), ['code' => Users::totp($user)])
        ->assertRedirect('/home');
    $this->assertAuthenticatedAs($user);
});

it('disables two-factor authentication', function () {
    $user = Users::withTwoFactor(User::factory()->create());

    $this->actingAs($user)->delete(route('two-factor.disable'))->assertSessionHasNoErrors();

    expect($user->fresh()->two_factor_secret)->toBeNull();
});
