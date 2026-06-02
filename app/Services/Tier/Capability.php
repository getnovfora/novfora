<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Tier;

/**
 * An environment-sensitive capability that sits behind a Laravel driver (ADR-0003).
 * Each has a baseline-safe default; the same code runs on the enhanced tier by config alone.
 */
enum Capability: string
{
    case Cache = 'cache';
    case Session = 'session';
    case Queue = 'queue';
    case Search = 'search';
    case Broadcast = 'broadcast';
    case Files = 'files';
    case Mail = 'mail';

    public function label(): string
    {
        return match ($this) {
            self::Cache => 'Cache',
            self::Session => 'Session',
            self::Queue => 'Queue',
            self::Search => 'Search',
            self::Broadcast => 'Real-time / broadcast',
            self::Files => 'File storage',
            self::Mail => 'Mail',
        };
    }
}
