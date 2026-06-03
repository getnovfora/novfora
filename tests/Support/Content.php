<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Support;

/** Tiny helpers for building canonical content in tests. */
final class Content
{
    /** A single-paragraph TipTap doc. @return array<string,mixed> */
    public static function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }
}
