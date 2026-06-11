<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\Captcha\QaCaptchaProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
| Phase-1.5 F-B: registration anti-abuse — a per-IP rate limit, a MANDATORY honeypot/timing token (the
| "just omit the token" skip is closed), and a single-use Q&A nonce so a captured answer can't be replayed.
| The suite default leaves these off (frictionless); each test opts the relevant control back in.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush(); // reset the rate-limiter + nonce cache between tests
    $this->seed();
});

it('throttles registration per IP with a 429 once the cap is exceeded', function () {
    config([
        'novfora.antispam.registration.rate_limit.enabled' => true,
        'novfora.antispam.registration.rate_limit.per_ip_per_hour' => 2,
    ]);

    // Invalid bodies still reach (and count toward) the throttle, and keep the client a guest.
    $body = ['username' => '', 'email' => 'x', 'password' => 'a', 'password_confirmation' => 'b'];

    $this->post('/register', $body);                       // 1
    $this->post('/register', $body);                       // 2
    $this->post('/register', $body)->assertStatus(429);    // 3 → throttled
});

it('rejects a registration that omits the timing token when it is required', function () {
    config(['novfora.antispam.registration.honeypot.required' => true]);

    $this->post('/register', [
        'username' => 'notoken', 'email' => 'notoken@example.test',
        'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
    ])->assertSessionHasErrors();

    $this->assertGuest();
    expect(User::where('email', 'notoken@example.test')->exists())->toBeFalse();
});

it('accepts a registration carrying a valid, old-enough timing token', function () {
    config(['novfora.antispam.registration.honeypot.required' => true]);

    $this->post('/register', [
        'username' => 'oktoken', 'email' => 'oktoken@example.test',
        'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
        'hp_ts' => encrypt((string) now()->subMinute()->timestamp), // rendered a minute ago → not too fast
    ])->assertRedirect();

    $this->assertAuthenticated();
});

it('rejects a replayed single-use Q&A nonce (a captured answer cannot be reused)', function () {
    config([
        'novfora.antispam.registration.captcha.qa.answers' => ['blue'],
        'novfora.antispam.registration.captcha.qa.single_use' => true,
    ]);

    $qa = new QaCaptchaProvider;
    $nonce = $qa->challenge()['nonce'];

    expect($qa->verify(['captcha_answer' => 'blue', 'captcha_nonce' => $nonce]))->toBeTrue();   // first use
    expect($qa->verify(['captcha_answer' => 'blue', 'captcha_nonce' => $nonce]))->toBeFalse();  // replay rejected
    expect($qa->verify(['captcha_answer' => 'blue']))->toBeFalse();                              // missing nonce rejected
});
