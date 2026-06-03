<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Install;

/** The non-secret outcome of an install run, surfaced on the wizard's "done" screen and by the CLI. */
final readonly class InstallResult
{
    /** @param list<string> $notes */
    public function __construct(
        public bool $storageLinked,
        public bool $demoSeeded,
        public array $notes = [],
    ) {}
}
