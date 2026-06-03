<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam\Captcha;

/**
 * Q&A challenge (ADR-0007 §2.5) — admin-defined question(s), no external dependency, so it is the
 * baseline-safe default and the degrade target for every external provider. Resists models trained on
 * public CAPTCHA image datasets.
 */
final class QaCaptchaProvider implements CaptchaProvider
{
    public function key(): string
    {
        return 'qa';
    }

    public function available(): bool
    {
        return true; // no external service — always usable on the baseline tier
    }

    public function challenge(): array
    {
        return [
            'type' => 'qa',
            'field' => 'captcha_answer',
            'question' => (string) config('hearth.antispam.registration.captcha.qa.question', ''),
        ];
    }

    public function verify(array $input): bool
    {
        $answer = strtolower(trim((string) ($input['captcha_answer'] ?? '')));
        if ($answer === '') {
            return false;
        }

        $accepted = array_map(
            fn ($a) => strtolower(trim((string) $a)),
            (array) config('hearth.antispam.registration.captcha.qa.answers', []),
        );

        return in_array($answer, $accepted, true);
    }
}
