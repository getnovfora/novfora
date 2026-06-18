{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- One forum/board row. Shared by the forum index (a cache-safe ForumNode) and the board view's
     "Sub-boards" block (a live Forum model) — both expose the same attributes, incl. last_posted_at +
     last_topic_id, so the "latest activity" column renders identically with no per-row query. --}}
<div class="flex items-start gap-3 p-4 hover:bg-surface-sunken">
    <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-accent-soft text-accent-soft-ink">
        <x-ui.icon name="message" class="h-5 w-5" />
    </span>

    <div class="min-w-0 flex-1">
        <a href="{{ route('forums.show', $forum->id) }}" class="block font-semibold text-ink hover:text-accent">{{ $forum->title }}</a>
        @if ($forum->description)
            <p class="mt-0.5 text-sm text-ink-muted">{{ $forum->description }}</p>
        @endif

        {{-- Mobile: counts + last activity stacked under the title. --}}
        <dl class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-subtle sm:hidden">
            <div class="flex items-center gap-1">
                <dt class="sr-only">{{ __('forum.topics_label') }}</dt>
                <dd class="nums font-medium text-ink-muted">{{ number_format($forum->topic_count) }}</dd><span>{{ __('forum.topics') }}</span>
            </div>
            <div class="flex items-center gap-1">
                <dt class="sr-only">{{ __('forum.posts_label') }}</dt>
                <dd class="nums font-medium text-ink-muted">{{ number_format($forum->post_count) }}</dd><span>{{ __('forum.posts') }}</span>
            </div>
            @if ($forum->last_posted_at)
                <div class="flex items-center gap-1">
                    <dt class="sr-only">{{ __('forum.latest_activity') }}</dt>
                    <dd class="nums">{{ __('forum.updated_ago', ['ago' => $forum->last_posted_at->diffForHumans()]) }}</dd>
                </div>
            @endif
        </dl>
    </div>

    {{-- Desktop: counts + a right-aligned "latest activity" column (links to the forum's most recent topic). --}}
    <div class="hidden shrink-0 items-start gap-6 sm:flex">
        <div class="text-right text-xs text-ink-subtle">
            <div><span class="nums font-semibold text-ink-muted">{{ number_format($forum->topic_count) }}</span> {{ __('forum.topics') }}</div>
            <div class="mt-0.5"><span class="nums font-semibold text-ink-muted">{{ number_format($forum->post_count) }}</span> {{ __('forum.posts') }}</div>
        </div>
        <div class="w-40 text-right text-xs">
            @if ($forum->last_posted_at)
                <span class="block text-ink-subtle">{{ __('forum.latest_activity') }}</span>
                @if ($forum->last_topic_id)
                    {{-- Accent + hover underline so the clickable timestamp is always distinct from the static
                         variant below (WCAG 1.4.1). --}}
                    <a href="{{ route('topics.show', $forum->last_topic_id) }}" class="block nums text-accent hover:underline">{{ $forum->last_posted_at->diffForHumans() }}</a>
                @else
                    <span class="block nums text-ink-muted">{{ $forum->last_posted_at->diffForHumans() }}</span>
                @endif
            @else
                <span class="block text-ink-subtle">{{ __('forum.no_posts_yet') }}</span>
            @endif
        </div>
    </div>
</div>
