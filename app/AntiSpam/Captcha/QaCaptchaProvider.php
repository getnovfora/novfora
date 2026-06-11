<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam\Captcha;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Q&A challenge (ADR-0007 §2.5) — admin-defined question(s), no external dependency, so it is the
 * baseline-safe default and the degrade target for every external provider. Resists models trained on
 * public CAPTCHA image datasets.
 *
 * Phase-1.5 F-B: each challenge is bound to a SINGLE-USE server-side nonce, so a captured (answer, nonce)
 * pair cannot be replayed — the nonce is consumed on the first successful verify. (Toggleable; off in the
 * test env.)
 */
final class QaCaptchaProvider implements CaptchaProvider
{
    private const NONCE_PREFIX = 'novfora:qa-nonce:';

    private const NONCE_FIELD = 'captcha_nonce';

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
        $challenge = [
            'type' => 'qa',
            'field' => 'captcha_answer',
            'question' => (string) config('novfora.antispam.registration.captcha.qa.question', ''),
        ];

        if ($this->singleUse()) {
            $nonce = Str::random(40);
            Cache::put(self::NONCE_PREFIX.$nonce, true, now()->addMinutes(30));
            $challenge['nonce_field'] = self::NONCE_FIELD;
            $challenge['nonce'] = $nonce;
        }

        return $challenge;
    }

    public function verify(array $input): bool
    {
        if (! $this->answerCorrect($input)) {
            return false;
        }

        // A correct answer must also carry a fresh, unused nonce — otherwise it's a replay (or a scripted
        // POST that never rendered a challenge). Consume on success so a typo doesn't burn the nonce.
        return $this->singleUse() ? $this->consumeNonce($input) : true;
    }

    /** @param array<string,mixed> $input */
    private function answerCorrect(array $input): bool
    {
        $answer = strtolower(trim((string) ($input['captcha_answer'] ?? '')));
        if ($answer === '') {
            return false;
        }

        $accepted = array_map(
            fn ($a) => strtolower(trim((string) $a)),
            (array) config('novfora.antispam.registration.captcha.qa.answers', []),
        );

        return in_array($answer, $accepted, true);
    }

    /** @param array<string,mixed> $input True iff the nonce exists and was unused (then deletes it). */
    private function consumeNonce(array $input): bool
    {
        $nonce = (string) ($input[self::NONCE_FIELD] ?? '');
        if ($nonce === '') {
            return false;
        }

        return (bool) Cache::pull(self::NONCE_PREFIX.$nonce); // single-use: pull returns the value and deletes it
    }

    private function singleUse(): bool
    {
        return (bool) config('novfora.antispam.registration.captcha.qa.single_use', true);
    }
}
