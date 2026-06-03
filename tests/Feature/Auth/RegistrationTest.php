<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\GroupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(GroupSeeder::class)); // default groups must exist to attach on register

it('registers a user, hashes with argon2id, and assigns the default groups', function () {
    $response = $this->post('/register', [
        'username' => 'ada',
        'email' => 'ada@hearth.test',
        'password' => 'correct horse battery staple',
        'password_confirmation' => 'correct horse battery staple',
    ]);

    $response->assertRedirect();
    $this->assertAuthenticated();

    $user = User::where('email', 'ada@hearth.test')->firstOrFail();
    expect($user->username)->toBe('ada');
    expect(str_starts_with($user->password, '$argon2id$'))->toBeTrue(); // argon2id, not bcrypt
    expect($user->groups->pluck('slug')->all())->toContain('members', 'tl0');
    expect((bool) $user->groups->firstWhere('slug', 'members')->pivot->is_primary)->toBeTrue();
    expect($user->hasVerifiedEmail())->toBeFalse(); // must verify email
});

it('requires a username', function () {
    $this->post('/register', [
        'username' => '',
        'email' => 'x@hearth.test',
        'password' => 'correct horse battery staple',
        'password_confirmation' => 'correct horse battery staple',
    ])->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('rejects a duplicate username', function () {
    User::factory()->create(['username' => 'taken']);

    $this->post('/register', [
        'username' => 'taken',
        'email' => 'new@hearth.test',
        'password' => 'correct horse battery staple',
        'password_confirmation' => 'correct horse battery staple',
    ])->assertSessionHasErrors('username');
});

it('rejects a mismatched password confirmation', function () {
    $this->post('/register', [
        'username' => 'bob',
        'email' => 'bob@hearth.test',
        'password' => 'correct horse battery staple',
        'password_confirmation' => 'nope',
    ])->assertSessionHasErrors('password');
});
