<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Tier;

/** The active driver + derived tier for one capability. */
final readonly class CapabilityStatus
{
    public function __construct(
        public Capability $capability,
        public string $driver,
        public Tier $tier,
    ) {}
}
