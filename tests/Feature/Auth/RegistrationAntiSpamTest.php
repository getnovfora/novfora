<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Ban;
use App\Models\RegistrationCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
| The anti-spam registration flow end-to-end (ADR-0007 §2.2): CAPTCHA + honeypot/timing reject bots, and the
| screener's tri-state decision maps to the account — block → rejected, flag → created but held (pending),
| allow → active. Strict config is opted in here (the suite default is frictionless).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'hearth.antispam.registration.stopforumspam.use_api' => true, // tests fake Http; no real network
        'hearth.antispam.registration.captcha.provider' => 'qa',
        'hearth.antispam.registration.captcha.qa.answers' => ['blue'],
    ]);
    $this->seed();
});

/** A clean StopForumSpam stub — set per test (Http::fake is first-match-wins, so never stack it). */
function sfsCleanFake(): void
{
    Http::fake(['api.stopforumspam.org/*' => Http::response(['success' => 1], 200)]);
}

function registration(array $overrides = []): array
{
    return array_merge([
        'username' => 'newcomer',
        'email' => 'newcomer@example.com',
        'password' => 'correct horse battery staple',
        'password_confirmation' => 'correct horse battery staple',
        'captcha_answer' => 'blue',
    ], $overrides);
}

it('lets a clean human registration through as an active account', function () {
    sfsCleanFake();

    $this->post('/register', registration())->assertRedirect();

    $this->assertAuthenticated();
    expect(User::where('email', 'newcomer@example.com')->firstOrFail()->status)->toBe('active');
});

it('rejects a wrong CAPTCHA answer', function () {
    $this->post('/register', registration(['captcha_answer' => 'red']))->assertSessionHasErrors('captcha_answer');

    $this->assertGuest();
    expect(User::where('email', 'newcomer@example.com')->exists())->toBeFalse();
});

it('rejects a filled honeypot', function () {
    $this->post('/register', registration(['hp_url' => 'http://bot.example']))->assertSessionHasErrors();

    $this->assertGuest();
});

it('rejects a form submitted implausibly fast (timing trap)', function () {
    $token = encrypt((string) now()->timestamp); // rendered "just now" → elapsed < min_seconds

    $this->post('/register', registration(['hp_ts' => $token]))->assertSessionHasErrors();

    $this->assertGuest();
});

it('blocks a high-confidence StopForumSpam listing', function () {
    Http::fake(['api.stopforumspam.org/*' => Http::response(['success' => 1, 'email' => ['appears' => 1, 'confidence' => 96]], 200)]);

    $this->post('/register', registration())->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('flags a borderline registration but lets the held account through (pending)', function () {
    Http::fake(['api.stopforumspam.org/*' => Http::response(['success' => 1, 'ip' => ['appears' => 1, 'confidence' => 30]], 200)]);

    $this->post('/register', registration())->assertRedirect();

    $user = User::where('email', 'newcomer@example.com')->firstOrFail();
    expect($user->status)->toBe('pending');
    expect(RegistrationCheck::where('email', 'newcomer@example.com')->where('decision', 'flag')->exists())->toBeTrue();
});

it('blocks a banned email address', function () {
    sfsCleanFake();
    Ban::create(['type' => 'email', 'value' => 'newcomer@example.com', 'scope_type' => 'global']);

    $this->post('/register', registration())->assertSessionHasErrors('email');
    $this->assertGuest();
});
