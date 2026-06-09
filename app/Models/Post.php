<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Forum\ReactionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Post extends Model
{
    use Searchable, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'body_canonical' => 'array', // lossless source (TipTap doc, or {"source": markdown})
        'position' => 'integer',
        'edit_count' => 'integer',
        'edited_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(fn (Post $post) => $post->syncAggregates(1));
        static::deleted(fn (Post $post) => $post->syncAggregates(-1));
        static::restored(fn (Post $post) => $post->syncAggregates(1));

        // A delete/restore changes which posts (and thus reaction tallies) are in scope for the topic, so
        // invalidate the RH-9 reaction-count cache (it is version-keyed per topic, not per post).
        static::deleted(fn (Post $post) => app(ReactionService::class)->invalidateTopic((int) $post->topic_id));
        static::restored(fn (Post $post) => app(ReactionService::class)->invalidateTopic((int) $post->topic_id));
    }

    /**
     * Search projection (ADR-0010). The baseline Scout `database` engine searches this model's `body_text`
     * column (MySQL FULLTEXT / LIKE); the enhanced Meilisearch engine indexes the same field. Only the
     * tags-stripped text projection is exposed — never raw HTML.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return ['body_text' => (string) $this->body_text];
    }

    /** Only approved content is discoverable (pending/rejected posts stay out of search). */
    public function shouldBeSearchable(): bool
    {
        return $this->approved_state === 'approved';
    }

    /** @return BelongsTo<Topic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PostRevision::class);
    }

    /** @return HasMany<Reaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    /** @return HasMany<PostReactionCount, $this> denormalised per-type tallies (read side) */
    public function reactionCounts(): HasMany
    {
        return $this->hasMany(PostReactionCount::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Maintain topic + forum aggregates (data-model §0) without COUNT(*) on read paths. Topic pointers +
     * reply_count are recomputed from indexed queries; the forum post_count moves by ±1 and the forum's
     * "last post" follows its most-recently-active topic (the listing index). Runs on create / soft-delete
     * / restore. The SoftDeletes scope means a just-trashed post is already excluded here.
     */
    public function syncAggregates(int $forumDelta): void
    {
        $topic = Topic::withTrashed()->find($this->topic_id);
        if (! $topic instanceof Topic) {
            return;
        }

        $posts = Post::where('topic_id', $topic->getKey());
        $total = (clone $posts)->count();
        $first = (clone $posts)->orderBy('position')->orderBy('id')->first();
        $last = (clone $posts)->orderByDesc('created_at')->orderByDesc('id')->first();

        $topic->forceFill([
            'first_post_id' => $first?->getKey(),
            'last_post_id' => $last?->getKey(),
            'last_post_user_id' => $last?->user_id,
            'last_posted_at' => $last?->created_at,
            'reply_count' => max(0, $total - 1),
        ])->saveQuietly();

        $forum = Forum::withTrashed()->find($topic->forum_id);
        if ($forum instanceof Forum) {
            $activeTopic = Topic::where('forum_id', $forum->getKey())
                ->whereNotNull('last_posted_at')
                ->orderByDesc('last_posted_at')
                ->first();

            $forum->forceFill([
                'post_count' => max(0, (int) $forum->post_count + $forumDelta),
                'last_post_id' => $activeTopic?->last_post_id,
                'last_topic_id' => $activeTopic?->getKey(),
                'last_posted_at' => $activeTopic?->last_posted_at,
            ])->saveQuietly();
        }
    }
}
