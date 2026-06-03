<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Permissions\AclVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Forum extends Model
{
    protected $guarded = [];

    protected $casts = [
        'depth' => 'integer',
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        // Maintain the materialised path + depth (ADR-0004) so the scope chain is O(depth), one query.
        // saveQuietly() fires no events, so this never re-triggers the topology-change bump below.
        static::created(function (Forum $forum) {
            $parent = $forum->parent_id ? static::find($forum->parent_id) : null;
            $forum->path = ($parent ? $parent->path : '/').$forum->id.'/';
            $forum->depth = $parent ? $parent->depth + 1 : 0;
            $forum->saveQuietly();
        });

        // Scope topology affects resolution (security §1.5): deleting or moving a node changes which
        // entries are in a chain, so it must invalidate resolved-permission caches like an ACL change.
        static::deleted(fn () => app(AclVersion::class)->bump());
        static::updated(function (Forum $forum) {
            if ($forum->wasChanged(['parent_id', 'path'])) {
                app(AclVersion::class)->bump();
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
