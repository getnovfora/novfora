<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Forum\ReactionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
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
        static::created(fn (Post $post) => $post->applyCreatedAggregates());
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
     * The CREATE half of the topic + forum aggregates (data-model §0), as ATOMIC statements (NF-08). A reply
     * is the hot, concurrent path: concurrent replies to one topic/forum must never lose a count or leave a
     * stale "last post". So nothing here is read-modify-write, and nothing depends on the transaction's read
     * snapshot (which, under MySQL REPEATABLE READ inside an outer transaction such as the scheduler's, can
     * predate a concurrent commit) — each UPDATE reads the latest committed row itself.
     *
     *   • topic: reply_count +1 — unless this is the opening post (first_post_id still null), which takes
     *     first_post_id instead. reply_count is listed FIRST: MySQL evaluates SET assignments left to right,
     *     so it must read first_post_id before that column is set (PostgreSQL/SQLite read old values anyway).
     *   • "last post" pointers (topic, then forum) advance only if this post is newer — (created_at, id), the
     *     same order the re-derivation uses — so out-of-order commits can't move them backwards.
     *   • forum post_count +1.
     *
     * Soft-delete / restore keep the authoritative re-derivation below (moderation paths, not the hot path);
     * the nightly novfora:forums:recompute-counters reconciles anything else.
     */
    public function applyCreatedAggregates(): void
    {
        $id = (int) $this->getKey();
        $topicId = (int) $this->topic_id;
        $at = $this->created_at;

        DB::table('topics')->where('id', $topicId)->update([
            'reply_count' => DB::raw('CASE WHEN first_post_id IS NULL THEN reply_count ELSE reply_count + 1 END'),
            'first_post_id' => DB::raw('COALESCE(first_post_id, '.$id.')'),
        ]);
        DB::table('topics')->where('id', $topicId)->where(fn ($q) => self::olderThan($q, $at, $id))
            ->update(['last_post_id' => $id, 'last_post_user_id' => $this->user_id, 'last_posted_at' => $at]);

        $forumId = DB::table('topics')->where('id', $topicId)->value('forum_id');
        if ($forumId === null) {
            return;
        }

        DB::table('forums')->where('id', $forumId)->update(['post_count' => DB::raw('post_count + 1')]);
        DB::table('forums')->where('id', $forumId)->where(fn ($q) => self::olderThan($q, $at, $id))
            ->update(['last_post_id' => $id, 'last_topic_id' => $topicId, 'last_posted_at' => $at]);
    }

    /** Rows whose "last post" is older than ($at, $id) in (created_at, id) order — i.e. this post is newer. */
    private static function olderThan(Builder $query, mixed $at, int $id): void
    {
        $query->whereNull('last_posted_at')
            ->orWhere('last_posted_at', '<', $at)
            ->orWhere(fn ($tie) => $tie->where('last_posted_at', '=', $at)
                ->where(fn ($p) => $p->whereNull('last_post_id')->orWhere('last_post_id', '<', $id)));
    }

    /**
     * Maintain topic + forum aggregates (data-model §0) without COUNT(*) on read paths, for soft-delete /
     * restore. Topic pointers + reply_count are recomputed from indexed queries; the forum post_count moves
     * by ±1 ATOMICALLY (floored at zero, NF-08) and the forum's "last post" follows its most-recently-active
     * topic (the listing index). The SoftDeletes scope means a just-trashed post is already excluded here.
     * (Creation uses the fully atomic applyCreatedAggregates() above.)
     */
    public function syncAggregates(int $forumDelta): void
    {
        // NF-08 review: lock the topic, then the forum — the same order as the reply path — BEFORE deriving, so a
        // delete/restore racing a reply waits for it and counts it, instead of overwriting its atomic +1 with a
        // total read before it committed. (Outside a caller's transaction the locks are the first statements,
        // so the derivation reads are fresh; the nightly recompute reconciles anything else.)
        DB::transaction(function () use ($forumDelta): void {
            $topic = Topic::withTrashed()->lockForUpdate()->find($this->topic_id);
            if (! $topic instanceof Topic) {
                return;
            }
            $forum = Forum::withTrashed()->lockForUpdate()->find($topic->forum_id);

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

            if ($forum instanceof Forum) {
                $activeTopic = Topic::where('forum_id', $forum->getKey())
                    ->whereNotNull('last_posted_at')
                    ->orderByDesc('last_posted_at')->orderByDesc('last_post_id') // (time, id): the same tie-break as the atomic create path (NF-08)
                    ->first();

                DB::table('forums')->where('id', $forum->getKey())->update([
                    'post_count' => DB::raw('CASE WHEN post_count + ('.$forumDelta.') < 0 THEN 0 ELSE post_count + ('.$forumDelta.') END'),
                ]);
                $forum->forceFill([
                    'last_post_id' => $activeTopic?->last_post_id,
                    'last_topic_id' => $activeTopic?->getKey(),
                    'last_posted_at' => $activeTopic?->last_posted_at,
                ])->saveQuietly();
            }
        });
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
