<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Messaging;

use RuntimeException;

/**
 * A RECOVERABLE private-message failure surfaced back to the sender (rate-limited, mass-PM cap exceeded, no
 * reachable recipient, target cannot be added). Distinct from AuthorizationException — that is a hard 403
 * (the user may not PM at all, or is not a participant); these are inline, retryable conditions the UI shows
 * as a validation error. The messages never reveal who ignores whom (block semantics).
 */
final class PmException extends RuntimeException
{
    public static function rateLimited(): self
    {
        return new self('You are sending messages too quickly. Please wait a moment and try again.');
    }

    public static function tooManyRecipients(int $max): self
    {
        return new self("A conversation can include at most {$max} recipients.");
    }

    public static function noValidRecipients(): self
    {
        return new self('None of the selected recipients can receive your message.');
    }

    public static function cannotAdd(): self
    {
        return new self('This user cannot be added to the conversation.');
    }
}
