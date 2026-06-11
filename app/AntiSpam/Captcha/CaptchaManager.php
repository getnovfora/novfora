<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam\Captcha;

/**
 * Resolves the CAPTCHA provider for an action (ADR-0007 §2.5), selectable per action and DEGRADING to the
 * baseline Q&A challenge whenever the configured provider is unavailable (e.g. an unconfigured external
 * service). This is the single guarantee that "the baseline never *requires* an external CAPTCHA service."
 */
final class CaptchaManager
{
    public function for(string $action = 'register'): CaptchaProvider
    {
        $key = (string) config(
            "novfora.antispam.registration.captcha.actions.{$action}",
            config('novfora.antispam.registration.captcha.provider', 'qa'),
        );

        $provider = $this->make($key);

        // Degrade an unavailable provider to Q&A — never error, never lock registration.
        return $provider->available() ? $provider : app(QaCaptchaProvider::class);
    }

    private function make(string $key): CaptchaProvider
    {
        return match ($key) {
            'turnstile' => app(TurnstileCaptchaProvider::class),
            'null', 'none', 'honeypot', 'invisible' => app(NullCaptchaProvider::class),
            default => app(QaCaptchaProvider::class),
        };
    }
}
