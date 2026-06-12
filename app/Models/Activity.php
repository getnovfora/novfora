<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Community\ActivityVersion;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An activity-feed entry (P2-M3). Append-only: no `updated_at`, no SoftDeletes. `actor_id` has no FK and is
 * pseudonymised to NULL by the ADR-0025 deletion cascade (then renders "[Deleted]"). A created event bumps
 * the feed's global cache version so the next read rebuilds.
 */
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    public const UPDATED_AT = null; // append-only log — created_at only

    public const VERB_TOPIC_CREATED = 'topic.created';

    public const VERB_POST_CREATED = 'post.created';

    public const VERB_REACT_GIVEN = 'react.given';

    protected $guarded = [];

    protected $casts = [
        'actor_id' => 'integer',
        'subject_id' => 'integer',
        'object_id' => 'integer',
        'scope_forum_id' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Any new activity invalidates the global feed cache (mirrors AclVersion).
        static::created(fn () => app(ActivityVersion::class)->bump());
    }

    /**
     * Append an activity. MUST be called AFTER the originating write has committed — the verb listeners fire
     * post-commit (Reacted is dispatched post-commit; TopicCreated/PostCreated likewise) — so a rolled-back
     * topic/post/reaction never logs an orphan activity.
     */
    public static function record(string $verb, Model $subject, ?int $actorId, ?int $scopeForumId = null, ?Model $object = null): self
    {
        return self::create([
            'actor_id' => $actorId,
            'verb' => $verb,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'object_type' => $object?->getMorphClass(),
            'object_id' => $object?->getKey(),
            'scope_forum_id' => $scopeForumId,
        ]);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function object(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Forum, $this> */
    public function scopeForum(): BelongsTo
    {
        return $this->belongsTo(Forum::class, 'scope_forum_id');
    }
}
