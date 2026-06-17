<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Permissions\AclVersion;
use App\Permissions\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Topic extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_pinned' => 'boolean',
        'reply_count' => 'integer',
        'view_count' => 'integer',
        'last_posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // A thread is a scope: deleting it or moving it to another forum changes resolution at that scope,
        // so it invalidates resolved-permission caches (security §1.5), like an ACL change.
        static::deleted(fn () => app(AclVersion::class)->bump());
        static::restored(fn () => app(AclVersion::class)->bump());
        static::updated(function (Topic $topic) {
            if ($topic->wasChanged('forum_id')) {
                app(AclVersion::class)->bump();
            }
        });

        // Denormalised forum.topic_count (data-model §0): adjust on create / soft-delete / restore.
        static::created(fn (Topic $t) => $t->adjustForumTopicCount(1));
        static::deleted(fn (Topic $t) => $t->adjustForumTopicCount(-1));
        static::restored(fn (Topic $t) => $t->adjustForumTopicCount(1));
    }

    public function forum(): BelongsTo
    {
        return $this->belongsTo(Forum::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The author of the most recent post (read-only; backs the "Last post" column on the board table).
     * The `last_post_user_id` column is already maintained by PostService — this only exposes it for display.
     *
     * @return BelongsTo<User, $this>
     */
    public function lastPostUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_post_user_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return BelongsTo<Poll, $this> the topic's poll via the topics.poll_id seam (null when none) */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    /** @return BelongsTo<Prefix, $this> the topic's prefix via the topics.prefix_id seam (null when none) */
    public function prefix(): BelongsTo
    {
        return $this->belongsTo(Prefix::class);
    }

    /** @return MorphToMany<Tag, $this> the topic's tags via the polymorphic taggables pivot */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function permissionScope(): Scope
    {
        return Scope::thread((int) $this->id);
    }

    /**
     * Whether new replies may be posted (the status gate only — the caller still enforces post.create and
     * club participation). Single source of truth shared by the web reply composer, TopicController, and the
     * REST API so the locked-topic moderation gate cannot drift between surfaces (P5.1).
     */
    public function isReplyable(): bool
    {
        return $this->status !== 'locked';
    }

    public function adjustForumTopicCount(int $delta): void
    {
        $forum = Forum::withTrashed()->find($this->forum_id);
        $forum?->forceFill(['topic_count' => max(0, (int) $forum->topic_count + $delta)])->saveQuietly();
    }
}
