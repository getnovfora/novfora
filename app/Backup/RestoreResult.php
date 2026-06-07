<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Backup;

use App\Upgrade\UpgradeResult;

/**
 * The outcome of a restore run (RH-11). `status` is 'success' | 'failed' | 'skipped'. For 'skipped',
 * {@see $reason} is a machine token (not-installed | nothing-pending | locked | stuck). For 'failed',
 * {@see $stage} is 'validate' | 'restore'. No secrets — safe to log / surface to an admin.
 *
 * Mirrors {@see UpgradeResult} so the restore choreography reads like the RH-10 upgrade one.
 */
final readonly class RestoreResult
{
    public function __construct(
        public string $status,
        public string $reason = '',
        public ?string $archive = null,
        public int $durationMs = 0,
        public ?string $safetyBackup = null,
        public ?string $dbDriver = null,
        public ?string $stage = null,
        public ?string $error = null,
    ) {}

    public static function success(string $archive, int $durationMs, ?string $safetyBackup, ?string $dbDriver): self
    {
        return new self('success', archive: $archive, durationMs: $durationMs, safetyBackup: $safetyBackup, dbDriver: $dbDriver);
    }

    public static function failed(string $stage, string $error, ?string $archive, ?string $safetyBackup = null): self
    {
        return new self('failed', archive: $archive, safetyBackup: $safetyBackup, stage: $stage, error: $error);
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', reason: $reason);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }
}
