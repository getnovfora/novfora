<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs in a verified user with correct credentials', function () {
    $user = User::factory()->create(['email' => 'ada@hearth.test']); // password = "password"

    $this->post('/login', ['email' => 'ada@hearth.test', 'password' => 'password'])
        ->assertRedirect('/home');

    $this->assertAuthenticatedAs($user);
});

it('rejects an incorrect password', function () {
    User::factory()->create(['email' => 'ada@hearth.test']);

    $this->post('/login', ['email' => 'ada@hearth.test', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('logs out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

it('throttles repeated failed logins (rate limiting)', function () {
    User::factory()->create(['email' => 'ada@hearth.test']);

    // The login route carries throttle:login (5/min per email+IP); the 6th attempt is blocked (429).
    foreach (range(1, 5) as $ignored) {
        $this->post('/login', ['email' => 'ada@hearth.test', 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => 'ada@hearth.test', 'password' => 'wrong'])
        ->assertStatus(429);
});
