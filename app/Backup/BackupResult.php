<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Backup;

/** A completed backup archive (M5). `kind` is the DB dump kind: 'sqlite' (file copy) or 'sql' (dump). */
final readonly class BackupResult
{
    public function __construct(
        public string $path,
        public int $sizeBytes,
        public string $kind,
    ) {}

    public function name(): string
    {
        return basename($this->path);
    }
}
