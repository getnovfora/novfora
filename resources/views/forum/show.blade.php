{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => $forum->title.' · '.config('app.name', 'NovFora')])

@push('head')
    {{-- SEO polish (discovery 3.4): canonical + Open Graph for the board. --}}
    <link rel="canonical" href="{{ route('forums.show', $forum) }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $forum->title }}">
    @if ($forum->description)<meta property="og:description" content="{{ $forum->description }}">@endif
    <meta property="og:url" content="{{ route('forums.show', $forum) }}">
    {{-- RSS/Atom auto-discovery (discovery 3.2). --}}
    <link rel="alternate" type="application/atom+xml" title="{{ $forum->title }} — feed" href="{{ route('feeds.forum', $forum) }}">
@endpush

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => __('common.forums'), 'url' => route('forums.index')],
        ['label' => $forum->title],
    ]" />
@endsection

@section('content')
    {{-- x-data scopes the page so Alpine initialises the bulk-select toggle + per-topic checkboxes (P2-M4),
         which read the global $store.bulkSelect; nested rows/components keep their own Alpine scopes. --}}
    <x-ui.container size="lg" class="space-y-5" x-data="{}"
        x-bind:style="$store.bulkSelect.active ? 'padding-bottom: 7rem' : ''">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $forum->title }}</h1>
                @if ($forum->description)
                    <p class="mt-1 text-sm text-ink-muted">{{ $forum->description }}</p>
                @endif
            </div>
            <div class="flex items-center gap-2">
                @if ($canModerate)
                    {{-- Bulk-select toggle (P2-M4): turns on per-topic checkboxes wired to the Alpine bulkSelect store. --}}
                    <x-ui.button type="button" variant="ghost" size="sm" dusk="bulk-select-toggle"
                                 x-on:click="$store.bulkSelect.toggleMode()"
                                 x-bind:class="$store.bulkSelect.active ? 'border-accent text-accent' : ''">
                        <span x-text="$store.bulkSelect.active ? @js(__('forum.done')) : @js(__('forum.select'))"></span>
                    </x-ui.button>
                @endif
                @if ($canPost)
                    <x-ui.button :href="route('topics.create', $forum)">
                        <x-ui.icon name="plus" class="h-4 w-4" /> {{ __('forum.new_topic') }}
                    </x-ui.button>
                @endif
            </div>
        </div>

        {{-- Theme Studio 1.3: configurable region — admin-placed widgets at the top of a board. --}}
        <x-region name="board_top" />

        @if ($canModerate)
            @include('partials.bulk-select-store')
            <livewire:forum.bulk-actions context="topics" :forum-id="$forum->id" />
        @endif

        {{-- Sub-boards (ProBoards-style) — child forums above the topic table, reusing the shared forum row. --}}
        @if ($children->isNotEmpty())
            <section class="space-y-2">
                <h2 class="px-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ __('forum.sub_boards') }}</h2>
                <x-ui.card flush>
                    <div class="divide-y divide-line">
                        @foreach ($children as $child)
                            @include('forum.partials.forum-row', ['forum' => $child])
                        @endforeach
                    </div>
                </x-ui.card>
            </section>
        @endif

        {{-- Prefix filter bar — shown only when this forum has at least one prefix. --}}
        @if ($prefixes->isNotEmpty())
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-ink-subtle">{{ __('forum.filter') }}</span>
                <a href="{{ route('forums.show', $forum) }}"
                   class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border transition-colors {{ request('prefix') === null ? 'border-accent bg-accent-soft text-accent-soft-ink' : 'border-line hover:border-accent text-ink-muted' }}">
                    {{ __('forum.filter_all') }}
                </a>
                @foreach ($prefixes as $prefix)
                    @php($pColor = \App\Support\GroupColor::cssVar($prefix->color_token))
                    <a href="{{ route('forums.show', $forum).'?prefix='.$prefix->id }}"
                       class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border transition-colors {{ (string) request('prefix') === (string) $prefix->id ? 'border-current' : 'border-line hover:border-current' }}"
                       @if ($pColor) style="color: {{ $pColor }};" @endif>
                        {{ $prefix->label }}
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Board-list style is a site Appearance setting (ACP v1): info-rich (the table) or minimal
             (the stacked list at all sizes). Presentation only — same topics, links, and selectors. --}}
        @php($boardListStyle = (app(\App\Settings\Settings::class)->siteView()['board_list_style'] ?? null) ?: 'info-rich')
        @if ($topics->isNotEmpty())
            {{-- Desktop: an info-dense topic table (Subject · Replies · Views · Last post). --}}
            @if ($boardListStyle === 'info-rich')
            <x-ui.card flush class="hidden overflow-hidden md:block">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-subtle">
                            <th scope="col" class="px-4 py-2.5">{{ __('forum.col_subject') }}</th>
                            <th scope="col" class="w-24 px-4 py-2.5 text-right">{{ __('forum.col_replies') }}</th>
                            <th scope="col" class="w-24 px-4 py-2.5 text-right">{{ __('forum.col_views') }}</th>
                            <th scope="col" class="w-52 px-4 py-2.5">{{ __('forum.col_last_post') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($topics as $topic)
                            @php($lastPage = max(1, (int) ceil(($topic->reply_count + 1) / 15)))
                            <tr class="align-top hover:bg-surface-sunken">
                                <td class="px-4 py-3">
                                    <div class="flex items-start gap-2.5">
                                        @if ($canModerate)
                                            <label x-show="$store.bulkSelect.active" x-cloak class="mt-0.5">
                                                <input type="checkbox" :checked="$store.bulkSelect.has({{ $topic->id }})"
                                                       x-on:change="$store.bulkSelect.toggle({{ $topic->id }})"
                                                       dusk="bulk-topic-{{ $topic->id }}"
                                                       class="h-4 w-4 rounded-sm border-line-strong text-accent focus-visible:ring-accent">
                                            </label>
                                        @endif
                                        <x-ui.avatar :user="$topic->author" size="sm" class="mt-0.5 hidden shrink-0 lg:inline-flex" />
                                        <div class="min-w-0">
                                            @if ($topic->is_pinned || $topic->status === 'locked' || $topic->prefix || $topic->tags->isNotEmpty())
                                                <div class="mb-0.5 flex flex-wrap items-center gap-1.5">
                                                    <x-forum.prefix-badge :prefix="$topic->prefix" />
                                                    @foreach ($topic->tags as $tag)
                                                        <x-forum.tag-chip :tag="$tag" />
                                                    @endforeach
                                                    @if ($topic->is_pinned)
                                                        <x-ui.badge variant="accent"><x-ui.icon name="pin" class="h-3 w-3" /> {{ __('forum.pinned') }}</x-ui.badge>
                                                    @endif
                                                    @if ($topic->status === 'locked')
                                                        <x-ui.badge variant="neutral"><x-ui.icon name="lock" class="h-3 w-3" /> {{ __('forum.locked') }}</x-ui.badge>
                                                    @endif
                                                </div>
                                            @endif
                                            <a href="{{ route('topics.show', $topic) }}" class="block font-semibold text-ink hover:text-accent">
                                                @if ($unread[$topic->id] ?? false)
                                                    <span class="mr-1.5 inline-block h-2 w-2 rounded-full bg-accent align-middle" aria-hidden="true"></span><span class="sr-only">{{ __('forum.unread') }} </span>
                                                @endif
                                                {{ $topic->title }}
                                            </a>
                                            <p class="text-xs text-ink-subtle">{{ __('forum.by') }} <x-ui.user-name :user="$topic->author" /></p>
                                            @if ($topic->firstPost?->body_text)
                                                {{-- M3: first-post excerpt (the cached OP text projection). --}}
                                                <p class="mt-0.5 line-clamp-1 text-xs text-ink-subtle">{{ \Illuminate\Support\Str::limit($topic->firstPost->body_text, 140) }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right align-middle nums text-ink-muted">{{ number_format($topic->reply_count) }}</td>
                                <td class="px-4 py-3 text-right align-middle nums text-ink-muted">{{ number_format($topic->view_count) }}</td>
                                <td class="px-4 py-3 align-middle">
                                    @if ($topic->last_posted_at)
                                        {{-- The poster name carries the link affordance (accent + hover underline,
                                             always-distinct from the adjacent meta — WCAG 1.4.1). --}}
                                        <a href="{{ route('topics.show', ['topic' => $topic, 'page' => $lastPage]).($topic->last_post_id ? '#post-'.$topic->last_post_id : '') }}" class="group block">
                                            <span class="block truncate font-medium text-accent group-hover:underline"><x-ui.user-name :user="$topic->lastPostUser" /></span>
                                            <span class="block text-xs text-ink-subtle nums">{{ $topic->last_posted_at->diffForHumans() }}</span>
                                        </a>
                                    @else
                                        <span class="text-xs text-ink-subtle">{{ __('forum.no_replies_yet') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
            @endif

            {{-- Stacked rows: the mobile reflow for "info-rich", and the whole list for "minimal"
                 (no horizontal scrolling — brief hard rule). --}}
            <x-ui.card flush class="{{ $boardListStyle === 'info-rich' ? 'md:hidden' : '' }}">
                <div class="divide-y divide-line">
                    @foreach ($topics as $topic)
                        @php($lastPage = max(1, (int) ceil(($topic->reply_count + 1) / 15)))
                        <div class="p-4 hover:bg-surface-sunken">
                            @if ($canModerate)
                                <label x-show="$store.bulkSelect.active" x-cloak class="mb-2 inline-flex items-center gap-2 text-xs text-ink-muted">
                                    <input type="checkbox" :checked="$store.bulkSelect.has({{ $topic->id }})"
                                           x-on:change="$store.bulkSelect.toggle({{ $topic->id }})"
                                           dusk="bulk-topic-{{ $topic->id }}"
                                           class="h-4 w-4 rounded-sm border-line-strong text-accent focus-visible:ring-accent">
                                    {{ __('forum.select') }}
                                </label>
                            @endif
                            @if ($topic->is_pinned || $topic->status === 'locked' || $topic->prefix || $topic->tags->isNotEmpty())
                                <div class="mb-0.5 flex flex-wrap items-center gap-1.5">
                                    <x-forum.prefix-badge :prefix="$topic->prefix" />
                                    @foreach ($topic->tags as $tag)
                                        <x-forum.tag-chip :tag="$tag" />
                                    @endforeach
                                    @if ($topic->is_pinned)
                                        <x-ui.badge variant="accent"><x-ui.icon name="pin" class="h-3 w-3" /> {{ __('forum.pinned') }}</x-ui.badge>
                                    @endif
                                    @if ($topic->status === 'locked')
                                        <x-ui.badge variant="neutral"><x-ui.icon name="lock" class="h-3 w-3" /> {{ __('forum.locked') }}</x-ui.badge>
                                    @endif
                                </div>
                            @endif
                            <a href="{{ route('topics.show', $topic) }}" class="block font-semibold text-ink hover:text-accent">
                                @if ($unread[$topic->id] ?? false)
                                    <span class="mr-1.5 inline-block h-2 w-2 rounded-full bg-accent align-middle" aria-hidden="true"></span><span class="sr-only">{{ __('forum.unread') }} </span>
                                @endif
                                {{ $topic->title }}
                            </a>
                            <p class="mt-0.5 text-sm text-ink-muted">{{ __('forum.by') }} <x-ui.user-name :user="$topic->author" /></p>
                            @if ($topic->firstPost?->body_text)
                                {{-- M3: first-post excerpt (the cached OP text projection). --}}
                                <p class="mt-1 line-clamp-2 text-sm text-ink-subtle">{{ \Illuminate\Support\Str::limit($topic->firstPost->body_text, 160) }}</p>
                            @endif
                            <dl class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-subtle">
                                <div class="flex items-center gap-1">
                                    <dt class="sr-only">{{ __('forum.col_replies') }}</dt>
                                    <dd class="nums font-medium text-ink-muted">{{ number_format($topic->reply_count) }}</dd><span>{{ __('forum.replies') }}</span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <dt class="sr-only">{{ __('forum.col_views') }}</dt>
                                    <dd class="nums font-medium text-ink-muted">{{ number_format($topic->view_count) }}</dd><span>{{ __('forum.views') }}</span>
                                </div>
                                @if ($topic->last_posted_at)
                                    <div class="flex items-center gap-1">
                                        <dt class="sr-only">{{ __('forum.col_last_post') }}</dt>
                                        <dd>
                                            <a href="{{ route('topics.show', ['topic' => $topic, 'page' => $lastPage]).($topic->last_post_id ? '#post-'.$topic->last_post_id : '') }}" class="text-accent hover:underline">{{ __('forum.last_by') }} <x-ui.user-name :user="$topic->lastPostUser" /></a>
                                            <span class="nums">· {{ $topic->last_posted_at->diffForHumans() }}</span>
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            <div>{{ $topics->links() }}</div>
        @else
            <x-ui.card flush>
                <x-ui.empty title="{{ __('forum.empty_topics_title') }}">
                    <x-slot:icon><x-ui.icon name="message" class="h-6 w-6" /></x-slot:icon>
                    @if ($canPost)
                        {{ __('forum.empty_topics_can_post') }}
                        <div class="mt-4">
                            <x-ui.button :href="route('topics.create', $forum)">
                                <x-ui.icon name="plus" class="h-4 w-4" /> {{ __('forum.start_a_topic') }}
                            </x-ui.button>
                        </div>
                    @else
                        {{ __('forum.empty_topics_guest') }}
                    @endif
                </x-ui.empty>
            </x-ui.card>
        @endif

        {{-- Theme Studio 1.3: configurable region — admin-placed widgets at the bottom of a board. --}}
        <x-region name="board_bottom" />
    </x-ui.container>
@endsection
