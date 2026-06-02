<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Tier;

/**
 * An immutable view of the active service tier: each capability's driver/tier, each optional
 * service's reachability, and the overall tier. Built without ever throwing.
 */
final readonly class TierSnapshot
{
    /**
     * @param  array<string, CapabilityStatus>  $capabilities  keyed by Capability->value
     * @param  array<string, ServiceStatus>  $services  keyed by probe key
     */
    public function __construct(
        public array $capabilities,
        public array $services,
        public Tier $overall,
    ) {}

    public function isEnhanced(Capability $c): bool
    {
        return ($this->capabilities[$c->value] ?? null)?->tier === Tier::Enhanced;
    }
}
