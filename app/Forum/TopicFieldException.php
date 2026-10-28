<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use RuntimeException;

/**
 * A topic custom-field value failed validation (U19 / NOV-116). Carries the offending field key so the caller
 * can attach the message to the right input.
 */
final class TopicFieldException extends RuntimeException
{
    public function __construct(public readonly string $fieldKey, string $message)
    {
        parent::__construct($message);
    }
}
