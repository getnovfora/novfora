{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- One forum/board row. Shared by the forum index (a cache-safe ForumNode) and the board view's
     "Sub-boards" block (a live Forum model) — both expose the same attributes, incl. last_posted_at +
     last_topic_id, so the "latest activity" column renders identically with no per-row query.

     F6 (member-audit gap): "latest activity" now names the last post's TOPIC + AUTHOR, not just a timestamp.
     The controller resolves them in ONE bounded query into $lastTopics (keyed by topic id); a row whose last
     topic isn't in the map (pending/removed, or this partial used without the map) degrades to the plain
     timestamp. The author renders through <x-ui.user-name> for the group colour — without :link, so it never
     nests inside the topic anchor. --}}
@php
    $lastTopics = $lastTopics ?? collect();
    $lt = $forum->last_topic_id ? $lastTopics->get($forum->last_topic_id) : null;
    $ltUrl = $lt ? route('topics.show', $lt).($lt->last_post_id ? '#post-'.$lt->last_post_id : '') : null;
@endphp
<div class="flex items-start gap-3 p-4 hover:bg-surface-sunken">
    <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-accent-soft text-accent-soft-ink">
        <x-ui.icon name="message" class="h-5 w-5" />
    </span>

    <div class="min-w-0 flex-1">
        <a href="{{ route('forums.show', $forum->slug ?: $forum->id) }}" class="block font-semibold text-ink hover:text-accent">{{ $forum->title }}</a>
        @if ($forum->description)
            <p class="mt-0.5 text-sm text-ink-muted">{{ $forum->description }}</p>
        @endif

        {{-- Mobile: counts + last activity stacked under the title. --}}
        <dl class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-subtle sm:hidden">
            <div class="flex items-center gap-1">
                <dt class="sr-only">{{ __('forum.topics_label') }}</dt>
                <dd class="nums font-medium text-ink-muted">{{ number_format($forum->topic_count) }}</dd><span>{{ trans_choice('forum.topics', $forum->topic_count) }}</span>
            </div>
            <div class="flex items-center gap-1">
                <dt class="sr-only">{{ __('forum.posts_label') }}</dt>
                <dd class="nums font-medium text-ink-muted">{{ number_format($forum->post_count) }}</dd><span>{{ trans_choice('forum.posts', $forum->post_count) }}</span>
            </div>
            @if ($forum->last_posted_at)
                <div class="flex w-full min-w-0 items-baseline gap-1">
                    <dt class="sr-only">{{ __('forum.latest_activity') }}</dt>
                    <dd class="min-w-0 truncate">
                        @if ($lt)
                            <a href="{{ $ltUrl }}" class="text-accent hover:underline">{{ $lt->title }}</a>
                            @if ($lt->lastPostUser)
                                <span class="text-ink-subtle">{{ __('forum.last_post_by') }} <x-ui.user-name :user="$lt->lastPostUser" /></span>
                            @endif
                            <span class="nums text-ink-subtle">· {{ $forum->last_posted_at->diffForHumans() }}</span>
                        @else
                            <span class="nums">{{ __('forum.updated_ago', ['ago' => $forum->last_posted_at->diffForHumans()]) }}</span>
                        @endif
                    </dd>
                </div>
            @endif
        </dl>
    </div>

    {{-- Desktop: counts + a right-aligned "latest activity" column (topic · by author · when). --}}
    <div class="hidden shrink-0 items-start gap-6 sm:flex">
        <div class="text-right text-xs text-ink-subtle">
            <div><span class="nums font-semibold text-ink-muted">{{ number_format($forum->topic_count) }}</span> {{ trans_choice('forum.topics', $forum->topic_count) }}</div>
            <div class="mt-0.5"><span class="nums font-semibold text-ink-muted">{{ number_format($forum->post_count) }}</span> {{ trans_choice('forum.posts', $forum->post_count) }}</div>
        </div>
        <div class="w-48 text-right text-xs">
            @if ($forum->last_posted_at)
                <span class="block text-ink-subtle">{{ __('forum.latest_activity') }}</span>
                @if ($lt)
                    {{-- Accent + hover underline so the clickable topic title is distinct from static text (WCAG 1.4.1). --}}
                    <a href="{{ $ltUrl }}" class="block truncate font-medium text-accent hover:underline" title="{{ $lt->title }}">{{ $lt->title }}</a>
                    <span class="block truncate text-ink-subtle">
                        @if ($lt->lastPostUser)
                            {{ __('forum.last_post_by') }} <x-ui.user-name :user="$lt->lastPostUser" />
                        @endif
                    </span>
                    <span class="block nums text-ink-subtle">{{ $forum->last_posted_at->diffForHumans() }}</span>
                @elseif ($forum->last_topic_id)
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
