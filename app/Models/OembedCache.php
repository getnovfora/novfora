<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cached oEmbed resolution (P2-M1): a sha256(url) → the trusted rendered embed/facade HTML + provider + TTL.
 * Written only by OEmbedService.
 */
class OembedCache extends Model
{
    protected $table = 'oembed_cache';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
