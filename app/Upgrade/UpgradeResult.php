<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Upgrade;

/**
 * The outcome of an upgrade run (RH-10). `status` is 'success' | 'failed' | 'skipped'. For 'skipped',
 * {@see $reason} is a machine token (not-installed | manual-mode | up-to-date | stuck | locked). For
 * 'failed', {@see $stage} is 'backup' | 'migrate'. No secrets — safe to log / surface to an admin.
 */
final readonly class UpgradeResult
{
    public function __construct(
        public string $status,
        public string $reason = '',
        public int $migrationsApplied = 0,
        public int $durationMs = 0,
        public ?string $backup = null,
        public ?string $stage = null,
        public ?string $error = null,
    ) {}

    public static function success(int $migrationsApplied, int $durationMs, ?string $backup): self
    {
        return new self('success', migrationsApplied: $migrationsApplied, durationMs: $durationMs, backup: $backup);
    }

    public static function failed(string $stage, string $error, ?string $backup): self
    {
        return new self('failed', stage: $stage, error: $error, backup: $backup);
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
