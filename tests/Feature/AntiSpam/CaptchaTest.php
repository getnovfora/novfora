<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\Captcha\CaptchaManager;
use App\AntiSpam\Captcha\QaCaptchaProvider;

/*
| CAPTCHA abstraction (ADR-0007 §2.5): the Q&A baseline needs no external service, and the manager DEGRADES
| an unavailable external provider to Q&A — the tier-graceful guarantee for the CAPTCHA layer.
*/

it('verifies the Q&A answer case-insensitively, rejecting wrong/empty answers', function () {
    config(['novfora.antispam.registration.captcha.qa.answers' => ['blue']]);
    $qa = new QaCaptchaProvider;

    expect($qa->verify(['captcha_answer' => '  BLUE ']))->toBeTrue();
    expect($qa->verify(['captcha_answer' => 'red']))->toBeFalse();
    expect($qa->verify([]))->toBeFalse();
});

it('returns the Q&A provider by default', function () {
    config(['novfora.antispam.registration.captcha.provider' => 'qa']);

    expect(app(CaptchaManager::class)->for('register')->key())->toBe('qa');
});

it('degrades an unconfigured Turnstile to Q&A (tier-graceful)', function () {
    config([
        'novfora.antispam.registration.captcha.provider' => 'turnstile',
        'novfora.antispam.registration.captcha.turnstile.secret' => '',
    ]);

    expect(app(CaptchaManager::class)->for('register')->key())->toBe('qa');
});

it('uses Turnstile when a secret is configured', function () {
    config([
        'novfora.antispam.registration.captcha.provider' => 'turnstile',
        'novfora.antispam.registration.captcha.turnstile.secret' => 'a-secret',
    ]);

    expect(app(CaptchaManager::class)->for('register')->key())->toBe('turnstile');
});

it('honours a per-action override', function () {
    config([
        'novfora.antispam.registration.captcha.provider' => 'qa',
        'novfora.antispam.registration.captcha.actions.register' => 'null',
    ]);

    expect(app(CaptchaManager::class)->for('register')->key())->toBe('null');
});
