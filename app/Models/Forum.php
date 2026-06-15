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
        'club_id' => 'integer',
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

    /** @return HasMany<Prefix, $this> */
    public function prefixes(): HasMany
    {
        return $this->hasMany(Prefix::class);
    }

    /**
     * The forum's most-recent topic (read-only; backs the index row's "latest activity" link). The
     * `last_topic_id` column is already maintained by PostService — this only exposes it for display.
     *
     * @return BelongsTo<Topic, $this>
     */
    public function lastTopic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'last_topic_id');
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

    // ── Club discussion (Phase 4 · M1.4) ─────────────────────────────────────────────────────────────────

    /** @return BelongsTo<Club, $this> */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function isClubForum(): bool
    {
        return $this->club_id !== null;
    }

    /**
     * May the viewer READ this forum's content? A board forum is governed solely by `forum.view` (checked by
     * the caller); a CLUB forum additionally requires the club's content to be visible to the viewer (public
     * club, or active member, or global staff). A soft-deleted/missing club resolves to hidden. This is the
     * single club read-gate the exposure surfaces consult (M1.5).
     */
    public function clubContentVisibleTo(?User $user): bool
    {
        if ($this->club_id === null) {
            return true;
        }

        return $this->club?->isContentVisibleTo($user) ?? false;
    }

    /**
     * May the viewer POST in this forum (start a topic / reply)? A board forum defers to the ACL; a CLUB forum
     * additionally requires ACTIVE membership (or global staff) — reading a public club is open, but posting
     * always requires joining first.
     */
    public function clubParticipationAllowed(?User $user): bool
    {
        if ($this->club_id === null) {
            return true;
        }

        return $this->club?->canParticipate($user) ?? false;
    }
}
