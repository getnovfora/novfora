<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Tier;

/** The configured/reachable status of one optional enhanced service, plus what it unlocks. */
final readonly class ServiceStatus
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $configured,
        public ?bool $reachable,
        public ?int $latencyMs,
        public ?string $note,
        public string $unlocks,
    ) {}
}
