<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam\Captcha;

/**
 * No visible challenge (ADR-0007 §2.5) — selected as 'null' / 'invisible' / 'honeypot' when an operator
 * relies on the always-on honeypot + timing trap instead of a user-facing CAPTCHA.
 */
final class NullCaptchaProvider implements CaptchaProvider
{
    public function key(): string
    {
        return 'null';
    }

    public function available(): bool
    {
        return true;
    }

    public function challenge(): array
    {
        return [];
    }

    public function verify(array $input): bool
    {
        return true;
    }
}
