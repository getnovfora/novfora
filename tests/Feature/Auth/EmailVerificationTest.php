<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

it('redirects an unverified user away from verified-only routes', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/home')->assertRedirect(route('verification.notice'));
});

it('verifies the email via a signed link', function () {
    Event::fake();
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    $this->actingAs($user)->get($url);

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('lets a verified user reach the account home', function () {
    $user = User::factory()->create(); // verified by default

    $this->actingAs($user)->get('/home')->assertOk()->assertSee('Welcome');
});
