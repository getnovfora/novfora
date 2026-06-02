<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Tier\Probes;

use Illuminate\Support\Facades\Storage;

class S3Probe extends Probe
{
    public function key(): string
    {
        return 's3';
    }

    public function label(): string
    {
        return 'S3-compatible storage (S3/MinIO)';
    }

    public function unlocks(): string
    {
        return 'Offloaded media storage that survives multi-node and CDN setups.';
    }

    public function configured(): bool
    {
        return config('filesystems.default') === 's3';
    }

    protected function check(): bool
    {
        // A lightweight existence check; the SDK applies its own connect timeouts. Any failure throws,
        // and the Probe base wrapper turns it into a graceful "down".
        Storage::disk('s3')->exists('.hearth-tier-probe');

        return true;
    }
}
