<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use RuntimeException;

/**
 * Thrown when post-time moderation REJECTS content (e.g. a 'block' word filter). The write path aborts and
 * the composer surfaces this as a form error — distinct from a HOLD, which writes the post as pending.
 */
final class ContentRejectedException extends RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons = [])
    {
        parent::__construct('Your post matched a content rule and was not published.');
    }
}
