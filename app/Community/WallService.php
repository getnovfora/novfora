<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Community;

use App\AntiSpam\ContentModerator;
use App\AntiSpam\ContentRejectedException;
use App\AntiSpam\WordFilterService;
use App\Content\ContentRenderer;
use App\Models\ProfilePost;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Support\Collection;

/**
 * Profile wall / status posts (◆-lite). The write side runs untrusted input through the SAME audited pipeline
 * as forum posts — render (sanitise) → moderate → word-filter — but with a deliberately MINIMAL feature set
 * (no links, no images, no embeds), shrinking the untrusted-input surface a status post can carry. The read
 * side applies two fences that must not drift:
 *   - approval  — an approved status is public; a PENDING one is visible ONLY to its own author (never leaked
 *                 to the owner or anyone else while it sits in the moderation queue).
 *   - ignore    — an author the VIEWER ignores is dropped from that viewer's wall read; and an author the
 *                 OWNER ignores cannot post on the owner's wall at all ({@see canPostOn}).
 * Only body_html_cache (already sanitised here) is ever rendered — never body_canonical or any client HTML.
 */
final class WallService
{
    /** Wall listing cap — a bounded, newest-first read; never an unbounded scan of a user's whole wall. */
    public const FEED_LIMIT = 20;

    /**
     * A status post carries no links, images, or embeds — the smallest sane rich-text surface. Passed to the
     * renderer as the DISALLOWED-feature list (its restriction contract), independent of the author's forum
     * post.links/post.images grants, since a wall is not a forum scope.
     */
    private const RESTRICT = ['links', 'images'];

    public function __construct(
        private readonly ContentRenderer $renderer,
        private readonly ContentModerator $moderator,
        private readonly WordFilterService $words,
        private readonly IgnoreService $ignores,
    ) {}

    /**
     * Post a status onto $owner's wall. The caller MUST have gated with {@see canPostOn} first (the controller
     * does, and rechecks); this method owns the content pipeline: sanitise → moderate (reject aborts, hold →
     * pending) → word-filter → persist. Returns the created (possibly pending) post.
     *
     * @param  array<string,mixed>  $canonical  the TipTap doc
     *
     * @throws ContentRejectedException when a moderation 'block' rule refuses the content
     */
    public function post(User $author, User $owner, string $format, array $canonical): ProfilePost
    {
        $rendered = $this->renderer->render($format, $canonical, self::RESTRICT);

        // Post-time moderation, identical semantics to a forum post: reject aborts; hold stores as pending.
        $verdict = $this->moderator->review($author, $rendered['text']);
        if ($verdict->rejected()) {
            throw new ContentRejectedException($verdict->reasons);
        }

        return ProfilePost::create([
            'profile_user_id' => $owner->getKey(),
            'user_id' => $author->getKey(),
            'body_format' => $format,
            'body_canonical' => $canonical,
            'body_html_cache' => $this->words->applyReplacements($rendered['html']),
            'body_text' => $this->words->applyReplacements($rendered['text']),
            'approved_state' => $verdict->held() ? 'pending' : 'approved',
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * The wall as $viewer may see it on $owner's profile — bounded, newest-first, no per-row query. Fences:
     * approved for everyone; a pending entry only for its own author; authors the viewer ignores are dropped.
     *
     * @return Collection<int, ProfilePost>
     */
    public function visibleWall(?User $viewer, User $owner, int $limit = self::FEED_LIMIT): Collection
    {
        $viewerId = $viewer?->getKey();
        $ignored = $viewer instanceof User ? $this->ignores->ignoredIds($viewer) : [];

        return ProfilePost::query()
            ->where('profile_user_id', $owner->getKey())
            ->where(function ($q) use ($viewerId) {
                $q->where('approved_state', 'approved');
                // A held status is shown back to its own author (so it isn't silently swallowed) — to nobody else.
                if ($viewerId !== null) {
                    $q->orWhere(fn ($p) => $p->where('approved_state', 'pending')->where('user_id', $viewerId));
                }
            })
            ->when($ignored !== [], fn ($q) => $q->whereNotIn('user_id', $ignored))
            ->with('author')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Who may post on $owner's wall (◆-lite): any authenticated user the owner has not ignored. Posting on your
     * own wall is allowed (you cannot ignore yourself). Ban/mute enforcement is upstream (the global ban guard)
     * and abusive content is still caught by {@see post}'s moderation review. The granular per-user privacy
     * matrix (members-only / followers-only / wall-off) is deferred to the full ◆ wall.
     */
    public function canPostOn(?User $author, User $owner): bool
    {
        if (! $author instanceof User) {
            return false; // a guest has no wall voice
        }

        return ! $this->ignores->ignores($owner, $author);
    }

    /** Delete a status: its author, the wall owner (their space to curate), or a global moderator. */
    public function canDelete(User $actor, ProfilePost $post): bool
    {
        return (int) $actor->getKey() === (int) $post->user_id
            || (int) $actor->getKey() === (int) $post->profile_user_id
            || $actor->canDo('bans.manage', Scope::global());
    }
}
