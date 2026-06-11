<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam\Captcha;

use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile (ADR-0007 §2.5) — the pluggable external example (enhanced tier). It is `available()`
 * only when a secret is configured; otherwise the CaptchaManager degrades to Q&A, so the baseline tier never
 * depends on it. hCaptcha/reCAPTCHA would be near-identical modules implementing this same contract.
 */
final class TurnstileCaptchaProvider implements CaptchaProvider
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function key(): string
    {
        return 'turnstile';
    }

    public function available(): bool
    {
        return (string) config('novfora.antispam.registration.captcha.turnstile.secret', '') !== '';
    }

    public function challenge(): array
    {
        return [
            'type' => 'turnstile',
            'field' => 'cf-turnstile-response',
            'site_key' => (string) config('novfora.antispam.registration.captcha.turnstile.site_key', ''),
        ];
    }

    public function verify(array $input): bool
    {
        $token = (string) ($input['cf-turnstile-response'] ?? '');
        if ($token === '') {
            return false;
        }

        try {
            $resp = Http::timeout(4)->connectTimeout(2)->asForm()->post(self::VERIFY_URL, [
                'secret' => (string) config('novfora.antispam.registration.captcha.turnstile.secret', ''),
                'response' => $token,
            ]);

            return $resp->ok() && $resp->json('success') === true;
        } catch (\Throwable) {
            // The verify endpoint is unreachable. Fail OPEN for a token the user did present — the honeypot,
            // StopForumSpam, velocity and new-user moderation layers still apply, so we don't lock everyone
            // out over a transient Cloudflare blip. (Provider *absence* is handled earlier by the manager.)
            return true;
        }
    }
}
