<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

it('emails a reset link', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@hearth.test']);

    $this->post('/forgot-password', ['email' => 'ada@hearth.test']);

    Notification::assertSentTo($user, ResetPassword::class);
});

it('resets the password with a valid token (argon2id)', function () {
    $user = User::factory()->create(['email' => 'ada@hearth.test']);
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'ada@hearth.test',
        'password' => 'a brand new passphrase',
        'password_confirmation' => 'a brand new passphrase',
    ])->assertSessionHasNoErrors();

    $fresh = $user->fresh();
    expect(Hash::check('a brand new passphrase', $fresh->password))->toBeTrue();
    expect(str_starts_with($fresh->password, '$argon2id$'))->toBeTrue();
});
