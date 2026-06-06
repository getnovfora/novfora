<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Permissions\AclVersion;
use App\Permissions\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Forum extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'depth' => 'integer',
        'position' => 'integer',
        'is_locked' => 'boolean',
        'settings' => 'array',
        'topic_count' => 'integer',
        'post_count' => 'integer',
        'last_posted_at' => 'datetime',
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

        // Scope topology affects resolution (security §1.5): deleting (incl. SOFT delete) or moving a node
        // changes which entries are in a chain, so it invalidates resolved-permission caches like an ACL
        // change. A soft-deleted forum is excluded from Forum::find, so the resolver inherits from its
        // surviving parent — exactly the §1.5 semantics, now realised by the recycle bin.
        static::deleted(fn () => app(AclVersion::class)->bump());
        static::restored(fn () => app(AclVersion::class)->bump());
        static::updated(function (Forum $forum) {
            if ($forum->wasChanged(['parent_id', 'path'])) {
                app(AclVersion::class)->bump();
            }
        });
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<Topic, $this> */
    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class);
    }

    public function isCategory(): bool
    {
        return $this->type === 'category';
    }

    /** The permission-engine scope this node represents (category vs forum). */
    public function permissionScope(): Scope
    {
        return $this->isCategory() ? Scope::category((int) $this->id) : Scope::forum((int) $this->id);
    }
}
