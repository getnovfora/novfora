<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

/**
 * The tri-state outcome of a registration screening (ADR-0007 §2.2): allow / flag (→ moderation) / block.
 * Mirrors the ACL's three states. Uncertain signals FLAG rather than block — losing a real member is worse
 * than one extra moderated account. `degraded` records that a provider was unreachable and a local fallback
 * was used (surfaced in admin metrics; never an error).
 */
final class ScreeningResult
{
    public const ALLOW = 'allow';

    public const FLAG = 'flag';

    public const BLOCK = 'block';

    /**
     * @param  array<string,mixed>  $scores  per-provider signals (recorded to registration_checks)
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly string $decision,
        public readonly array $scores = [],
        public readonly bool $degraded = false,
        public readonly array $reasons = [],
    ) {}

    public function allowed(): bool
    {
        return $this->decision === self::ALLOW;
    }

    public function flagged(): bool
    {
        return $this->decision === self::FLAG;
    }

    public function blocked(): bool
    {
        return $this->decision === self::BLOCK;
    }
}
