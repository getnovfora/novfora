<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A trusted ed25519 public key (U17, ADR-0104). A signed module package installs only if an ENABLED key here
 * validates its detached signature. Written only through the audited App\Modules\ModuleTrustKeys; never
 * mass-assigned from a request.
 *
 * @property string $name
 * @property string $public_key base64 ed25519 public key
 * @property string $fingerprint sha-256(raw key) hex — the stable human id
 * @property bool $is_enabled
 */
class ModuleTrustKey extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['is_enabled' => 'bool'];
    }
}
