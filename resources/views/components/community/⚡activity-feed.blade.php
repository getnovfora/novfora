<?php
// SPDX-License-Identifier: Apache-2.0

use App\Community\ActivityFeed;
use App\Community\FollowService;
use App\Models\Activity;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The community activity feed (P2-M3; following mode P2-M5). Read-only and PUBLIC — the forum index is
 * public, so guests see it too; there is no own-data to protect, hence no auth assertion (the viewer is
 * resolved guest-aware, exactly like ForumController). The per-viewer permission filter + cache discipline
 * live in ActivityFeed; the items are a Computed (never serialised into the Livewire snapshot).
 *
 * FOLLOWING mode (signed-in only): the same feed restricted to followed actors — still permission-filtered
 * through VisibleForumIds inside ActivityFeed (never bypassed). An empty follow set falls back to the
 * global feed with a "follow people to personalise this" hint (recorded decision, DECISIONS P2-M5).
 */
new class extends Component
{
    /** 'all' | 'following' — following is signed-in only; guests never see the tab. */
    public string $mode = 'all';

    public function setMode(string $mode): void
    {
        abort_unless(in_array($mode, ['all', 'following'], true), 422);

        if ($mode === 'following' && ! auth()->check()) {
            $mode = 'all'; // a guest cannot have a follow graph; never trust the wire payload
        }

        $this->mode = $mode;
        unset($this->feed); // reset the computed cache so the new mode recomputes
    }

    /** @return array{items: list<\App\Community\ActivityFeedItem>, fallback: bool} */
    #[Computed]
    public function feed(): array
    {
        $viewer = auth()->user() ?? User::guest();
        $feed = app(ActivityFeed::class);

        if ($this->mode === 'following' && $viewer->exists) {
            $followedIds = app(FollowService::class)->followingIds($viewer);

            if ($followedIds !== []) {
                return ['items' => $feed->forFollowing($viewer, $followedIds), 'fallback' => false];
            }

            // Empty follow set → the global feed plus the personalisation hint.
            return ['items' => $feed->for($viewer), 'fallback' => true];
        }

        return ['items' => $feed->for($viewer), 'fallback' => false];
    }
};
?>

<section dusk="activity-feed" class="space-y-3" aria-label="{{ __('Recent activity') }}">
    <div class="flex items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-ink">{{ __('Recent activity') }}</h2>
        @auth
            <div class="flex items-center gap-1" role="tablist" aria-label="{{ __('Activity feed mode') }}">
                <button type="button" wire:click="setMode('all')" dusk="feed-tab-all"
                        role="tab" aria-selected="{{ $mode === 'all' ? 'true' : 'false' }}"
                        @class([
                            'rounded-full px-2.5 py-1 text-xs font-medium transition',
                            'bg-accent-soft text-accent' => $mode === 'all',
                            'text-ink-muted hover:text-ink' => $mode !== 'all',
                        ])>{{ __('All') }}</button>
                <button type="button" wire:click="setMode('following')" dusk="feed-tab-following"
                        role="tab" aria-selected="{{ $mode === 'following' ? 'true' : 'false' }}"
                        @class([
                            'rounded-full px-2.5 py-1 text-xs font-medium transition',
                            'bg-accent-soft text-accent' => $mode === 'following',
                            'text-ink-muted hover:text-ink' => $mode !== 'following',
                        ])>{{ __('Following') }}</button>
            </div>
        @endauth
    </div>

    @if ($this->feed['fallback'])
        <p dusk="feed-follow-hint" class="rounded-lg border border-line bg-surface-sunken px-3 py-2 text-xs text-ink-muted">
            {{ __('You are not following anyone yet — showing all activity. Follow people from their profile to personalise this feed.') }}
        </p>
    @endif

    <x-ui.card flush>
        @forelse ($this->feed['items'] as $item)
            @php($phrase = match ($item->verb) {
                Activity::VERB_TOPIC_CREATED => __('started a topic'),
                Activity::VERB_POST_CREATED => __('replied in'),
                Activity::VERB_REACT_GIVEN => __('reacted to a post in'),
                default => __('posted in'),
            })
            <div dusk="activity-row" class="flex items-start gap-3 border-b border-line px-4 py-3">
                <div class="relative shrink-0">
                    <x-ui.avatar :user="$item->actor" :guest="$item->actor === null" size="sm" />
                    <x-ui.online-badge :user="$item->actor" dusk="online-dot" class="absolute bottom-0 right-0" />
                </div>
                <div class="min-w-0 flex-1 text-sm">
                    <p class="text-ink">
                        <span class="font-medium"><x-ui.user-name :user="$item->actor" :fallback="__('[Deleted]')" /></span>
                        <span class="text-ink-subtle">{{ $phrase }}</span>
                        @if ($item->isMissing())
                            <span class="italic text-ink-subtle">{{ __('a removed item') }}</span>
                        @else
                            <a href="{{ $item->url() }}" wire:navigate
                               class="font-medium text-accent hover:text-accent-hover break-words">{{ $item->title() }}</a>
                        @endif
                    </p>
                    @if ($item->createdAt)
                        <p class="nums text-xs text-ink-subtle" title="{{ $item->createdAt->toDateTimeString() }}">
                            {{ $item->createdAt->diffForHumans() }}
                        </p>
                    @endif
                </div>
            </div>
        @empty
            <p class="px-4 py-6 text-center text-sm text-ink-subtle">
                @if ($mode === 'following')
                    {{ __('No recent activity from people you follow.') }}
                @else
                    {{ __('No recent activity yet.') }}
                @endif
            </p>
        @endforelse
    </x-ui.card>
</section>
