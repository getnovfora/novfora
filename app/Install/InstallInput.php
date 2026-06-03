<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Install;

/**
 * An immutable, already-validated bundle of installer inputs. Constructed by the web wizard (after
 * Livewire validation) and by the CLI command (after prompt/option validation), then handed to
 * {@see InstallRunner} — so both entry points run the exact same install sequence (DRY, one code path
 * to secure and test). The plaintext admin password lives here only for the duration of the run; it is
 * hashed (argon2id) by the runner and never persisted or echoed.
 */
final readonly class InstallInput
{
    public function __construct(
        public string $siteName,
        public string $appUrl,
        public string $dbDriver,
        public string $dbHost,
        public int $dbPort,
        public string $dbDatabase,
        public string $dbUsername,
        public string $dbPassword,
        public string $adminUsername,
        public string $adminEmail,
        public string $adminPassword,
        public bool $seedDemo = false,
    ) {}
}
