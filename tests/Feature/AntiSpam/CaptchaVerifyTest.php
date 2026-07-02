<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\Captcha\HCaptchaProvider;
use App\AntiSpam\Captcha\RecaptchaProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
| U18 (ADR-0107): server-side verification for the hCaptcha / reCAPTCHA drivers. These are FAIL-CLOSED —
| an unverifiable token (provider 5xx, connection failure) is a FAILED challenge, never a pass. That is a
| deliberate divergence from Turnstile's shipped fail-open, because the verify path is an untrusted-input
| boundary. And challenge() must only ever expose the public site key — the secret never reaches the page.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    config([
        'novfora.antispam.registration.captcha.hcaptcha.site_key' => 'hcaptcha-site-key-abc',
        'novfora.antispam.registration.captcha.hcaptcha.secret' => 'hcaptcha-secret-xyz',
        'novfora.antispam.registration.captcha.recaptcha.site_key' => 'recaptcha-site-key-abc',
        'novfora.antispam.registration.captcha.recaptcha.secret' => 'recaptcha-secret-xyz',
    ]);
});

dataset('external captcha providers', [
    'hcaptcha' => [HCaptchaProvider::class, 'h-captcha-response', 'api.hcaptcha.com/siteverify', 'hcaptcha-secret-xyz'],
    'recaptcha' => [RecaptchaProvider::class, 'g-recaptcha-response', 'www.google.com/recaptcha/api/siteverify', 'recaptcha-secret-xyz'],
]);

it('accepts a token the provider verifies, POSTing secret+response to the right endpoint', function (string $class, string $field, string $endpoint, string $secret) {
    Http::fake(['*' => Http::response(['success' => true])]);

    expect(app($class)->verify([$field => 'tok-1']))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), $endpoint)
        && $request['secret'] === $secret
        && $request['response'] === 'tok-1');
})->with('external captcha providers');

it('rejects a token the provider says failed (success=false)', function (string $class, string $field) {
    Http::fake(['*' => Http::response(['success' => false])]);

    expect(app($class)->verify([$field => 'tok-bad']))->toBeFalse();
})->with('external captcha providers');

it('FAIL-CLOSED: a provider 500 rejects the token', function (string $class, string $field) {
    Http::fake(['*' => Http::response(null, 500)]);

    expect(app($class)->verify([$field => 'tok-1']))->toBeFalse();
})->with('external captcha providers');

it('FAIL-CLOSED: an unreachable provider rejects the token (never throws, never passes)', function (string $class, string $field) {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(app($class)->verify([$field => 'tok-1']))->toBeFalse();
})->with('external captcha providers');

it('rejects an empty/absent token without any HTTP call', function (string $class, string $field) {
    Http::fake();

    expect(app($class)->verify([]))->toBeFalse()
        ->and(app($class)->verify([$field => '']))->toBeFalse();

    Http::assertNothingSent();
})->with('external captcha providers');

it('renders the hCaptcha widget with ONLY the site key — the secret never reaches the page', function () {
    config(['novfora.antispam.registration.captcha.provider' => 'hcaptcha']);

    $resp = $this->get('/register')->assertOk();

    $resp->assertSee('hcaptcha-site-key-abc');
    $resp->assertSee('h-captcha', false);
    $resp->assertSee('js.hcaptcha.com/1/api.js', false);
    $resp->assertDontSee('hcaptcha-secret-xyz');
});

it('renders the reCAPTCHA widget with ONLY the site key — the secret never reaches the page', function () {
    config(['novfora.antispam.registration.captcha.provider' => 'recaptcha']);

    $resp = $this->get('/register')->assertOk();

    $resp->assertSee('recaptcha-site-key-abc');
    $resp->assertSee('g-recaptcha', false);
    $resp->assertSee('www.google.com/recaptcha/api.js', false);
    $resp->assertDontSee('recaptcha-secret-xyz');
});
