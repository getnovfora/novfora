<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Forum\ReactionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
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

        // Maintain the denormalised users.post_count alongside the forum aggregate (data-model §0). A post
        // counts for its author the moment it exists and uncounts on (soft-)delete; restore re-counts. Since
        // rejection soft-deletes the post, a held-then-rejected post nets to zero — exactly mirroring how
        // forums.post_count is moved by the same create/delete/restore deltas above.
        static::created(fn (Post $post) => $post->adjustAuthorPostCount(1));
        static::deleted(fn (Post $post) => $post->adjustAuthorPostCount(-1));
        static::restored(fn (Post $post) => $post->adjustAuthorPostCount(1));

        // A delete/restore changes which posts (and thus reaction tallies) are in scope for the topic, so
        // invalidate the RH-9 reaction-count cache (it is version-keyed per topic, not per post).
        static::deleted(fn (Post $post) => app(ReactionService::class)->invalidateTopic((int) $post->topic_id));
        static::restored(fn (Post $post) => app(ReactionService::class)->invalidateTopic((int) $post->topic_id));
    }

    /**
     * Search projection (ADR-0010; P2-M4 facets). The baseline Scout `database` engine LIKE-matches over the
     * keys of this array as real columns, so on the DB tier it stays the tags-stripped `body_text` projection
     * ALONE — never raw HTML, and never a numeric column that would pollute keyword matching. The enhanced
     * Meilisearch engine additionally needs the facet fields as filterable attributes (forum_id lives on the
     * topic, so it is computed via the relation); these are added ONLY for that driver, where they are
     * filtered, not LIKE-matched. The DB tier resolves the same facets through SearchService's topic join.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $data = ['body_text' => (string) $this->body_text];

        if (in_array(config('scout.driver'), ['meilisearch', 'typesense'], true)) {
            // forum_id lives on the topic; resolve withTrashed so a post under a soft-deleted topic still
            // indexes a forum (index-time only, on the enhanced tier — the DB tier never reaches here). The
            // driver set matches MeilisearchProbe::configured() so Typesense indexes the facet fields too.
            $data['user_id'] = (int) $this->user_id;
            $data['topic_id'] = (int) $this->topic_id;
            $data['forum_id'] = (int) (Topic::withTrashed()->whereKey($this->topic_id)->value('forum_id') ?? 0);
            $data['created_at'] = $this->created_at?->getTimestamp();
        }

        return $data;
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

    /**
     * Atomically move the author's denormalised users.post_count by the given delta (data-model §0). A direct,
     * event-free query-builder UPDATE keeps it concurrency-safe (no read-modify-write race), and it avoids
     * touching the user's timestamps or firing model events. The decrement is floored at zero so a double-fire
     * or an out-of-order event can never drive the count negative.
     */
    public function adjustAuthorPostCount(int $delta): void
    {
        $userId = (int) $this->user_id;
        if ($userId <= 0 || $delta === 0) {
            return;
        }

        $query = DB::table('users')->where('id', $userId);
        if ($delta < 0) {
            $query->where('post_count', '>', 0);
        }

        $query->update(['post_count' => DB::raw('post_count + ('.(int) $delta.')')]);
    }
}
