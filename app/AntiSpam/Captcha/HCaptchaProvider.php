<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam\Captcha;

use Illuminate\Support\Facades\Http;

/**
 * hCaptcha (U18, ADR-0107) — a pluggable external provider (enhanced tier) mirroring the Turnstile module.
 * It is `available()` only when a secret is configured; otherwise the CaptchaManager degrades to Q&A, so
 * the baseline tier never depends on it. challenge() exposes ONLY the public site key — never the secret.
 */
final class HCaptchaProvider implements CaptchaProvider
{
    private const VERIFY_URL = 'https://api.hcaptcha.com/siteverify';

    public function key(): string
    {
        return 'hcaptcha';
    }

    public function available(): bool
    {
        return (string) config('novfora.antispam.registration.captcha.hcaptcha.secret', '') !== '';
    }

    public function challenge(): array
    {
        return [
            'type' => 'hcaptcha',
            'field' => 'h-captcha-response',
            'site_key' => (string) config('novfora.antispam.registration.captcha.hcaptcha.site_key', ''),
        ];
    }

    public function verify(array $input): bool
    {
        $token = (string) ($input['h-captcha-response'] ?? '');
        if ($token === '') {
            return false;
        }

        try {
            $resp = Http::timeout(4)->connectTimeout(2)->asForm()->post(self::VERIFY_URL, [
                'secret' => (string) config('novfora.antispam.registration.captcha.hcaptcha.secret', ''),
                'response' => $token,
            ]);

            return $resp->ok() && $resp->json('success') === true;
        } catch (\Throwable) {
            // FAIL CLOSED — a deliberate divergence from TurnstileCaptchaProvider's fail-open (ADR-0107).
            // This is an untrusted-input boundary: an unverifiable token is treated as a failed challenge,
            // never as a pass. Registration is not locked out — the manager already degrades an
            // *unconfigured* provider to Q&A, and the user can simply retry once the endpoint recovers.
            return false;
        }
    }
}
