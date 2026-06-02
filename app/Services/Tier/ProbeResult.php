<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Tier;

/**
 * The outcome of probing one optional service. Never thrown — always returned.
 * `note` is a short, safe, human summary (no secrets, no stack traces).
 */
final readonly class ProbeResult
{
    public function __construct(
        public bool $configured,
        public ?bool $reachable,
        public ?int $latencyMs = null,
        public ?string $note = null,
    ) {}

    public static function up(int $latencyMs): self
    {
        return new self(configured: true, reachable: true, latencyMs: $latencyMs, note: 'reachable');
    }

    public static function down(string $note = 'unreachable'): self
    {
        return new self(configured: true, reachable: false, latencyMs: null, note: $note);
    }

    public static function notConfigured(): self
    {
        return new self(configured: false, reachable: null, latencyMs: null, note: 'not configured');
    }
}
