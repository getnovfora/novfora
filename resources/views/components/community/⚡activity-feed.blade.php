<?php

// SPDX-License-Identifier: Apache-2.0

use App\Community\ActivityFeed;
use App\Models\Activity;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The community activity feed (P2-M3). Read-only and PUBLIC — the forum index is public, so guests see it
 * too; there is no own-data to protect, hence no auth assertion (the viewer is resolved guest-aware, exactly
 * like ForumController). The per-viewer permission filter + cache discipline live in ActivityFeed; the items
 * are a Computed (never serialised into the Livewire snapshot).
 */
new class extends Component
{
    /** @return list<\App\Community\ActivityFeedItem> */
    #[Computed]
    public function items(): array
    {
        $viewer = auth()->user() ?? User::guest();

        return app(ActivityFeed::class)->for($viewer);
    }
};
?>

<section dusk="activity-feed" class="space-y-3" aria-label="{{ __('Recent activity') }}">
    <h2 class="text-sm font-semibold text-ink">{{ __('Recent activity') }}</h2>
    <x-ui.card flush>
        @forelse ($this->items as $item)
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
            <p class="px-4 py-6 text-center text-sm text-ink-subtle">{{ __('No recent activity yet.') }}</p>
        @endforelse
    </x-ui.card>
</section>
