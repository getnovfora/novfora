<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Provenance of a package installed from the NovFora Registry (NOV-125 / ADR-0113): its slug, type, version,
 * and the publisher fingerprint that signed it — the join a feed revocation is checked against. Written only
 * by RegistryClient.
 */
class RegistryInstall extends Model
{
    protected $fillable = ['slug', 'type', 'version', 'publisher_fingerprint', 'target', 'installed_at'];

    protected $casts = ['installed_at' => 'datetime'];
}
