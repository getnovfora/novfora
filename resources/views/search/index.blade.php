{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => ($q !== '' ? "Search: {$q}" : 'Search').' · '.config('app.name', 'NovFora')])

{{-- $query / $forums are supplied by SearchController; tolerate their absence (e.g. a direct view() render). --}}
@php($query = $query ?? null)
@php($forums = $forums ?? collect())
@php($searched = $q !== '' || (bool) $query?->hasFacets())
@php($facetsOpen = (bool) $query?->hasFacets())

@section('content')
    {{-- size="lg" follows the site Appearance "Forum width" (--layout-max-width), like the index/board/topic
         views — search results are main content, so the width setting governs them too. --}}
    <x-ui.container size="lg" class="space-y-5">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">Search</h1>

        {{-- Faceted GET form (P2-M4): keyword + collapsible facets, all in query params so a search is
             bookmarkable. Visibility is enforced server-side — the forum dropdown lists only forums the
             viewer can see. --}}
        <form method="GET" action="{{ route('search.index') }}" role="search" class="space-y-3"
              x-data="{ facets: @js($facetsOpen) }">
            <div class="flex flex-col gap-2 sm:flex-row">
                <label for="q" class="sr-only">Search posts</label>
                <div class="relative flex-1">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-subtle">
                        <x-ui.icon name="search" class="h-4 w-4" />
                    </span>
                    <input id="q" type="search" name="q" value="{{ $q }}" placeholder="Search posts…" autofocus
                           class="w-full min-h-11 pl-9 pr-3 rounded-md bg-surface-raised text-ink placeholder:text-ink-subtle border border-line transition-colors focus:border-accent">
                </div>
                <x-ui.button type="button" variant="ghost" x-on:click="facets = ! facets" dusk="search-facets-toggle">
                    Filters
                </x-ui.button>
                <x-ui.button type="submit" dusk="search-submit">Search</x-ui.button>
            </div>

            <div x-show="facets" x-cloak class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <label for="facet-forum" class="block text-sm font-medium text-ink">Forum</label>
                    <select id="facet-forum" name="forum" dusk="facet-forum"
                            class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border border-line focus:border-accent">
                        <option value="">Any forum</option>
                        @foreach ($forums as $f)
                            <option value="{{ $f->id }}" @selected((string) request('forum') === (string) $f->id)>{{ $f->title }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label for="facet-author" class="block text-sm font-medium text-ink">Author (username)</label>
                    <input id="facet-author" type="text" name="author" value="{{ request('author') }}" dusk="facet-author"
                           class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border border-line focus:border-accent">
                </div>

                <div class="space-y-1.5">
                    <label for="facet-type" class="block text-sm font-medium text-ink">Type</label>
                    <select id="facet-type" name="type" dusk="facet-type"
                            class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border border-line focus:border-accent">
                        <option value="post" @selected(request('type', 'post') !== 'topic')>Any post</option>
                        <option value="topic" @selected(request('type') === 'topic')>Opening posts only</option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label for="facet-from" class="block text-sm font-medium text-ink">From</label>
                    <input id="facet-from" type="date" name="from" value="{{ request('from') }}" dusk="facet-from"
                           class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border border-line focus:border-accent">
                </div>

                <div class="space-y-1.5">
                    <label for="facet-to" class="block text-sm font-medium text-ink">To</label>
                    <input id="facet-to" type="date" name="to" value="{{ request('to') }}" dusk="facet-to"
                           class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border border-line focus:border-accent">
                </div>
            </div>
        </form>

        @if ($searched)
            <p class="text-sm text-ink-muted">
                <span class="nums">{{ $results->count() }}</span>
                {{ \Illuminate\Support\Str::plural('result', $results->count()) }}@if ($q !== '') for “{{ $q }}”@endif
            </p>

            @if ($results->count())
                <x-ui.card flush>
                    <ul class="divide-y divide-line">
                        @foreach ($results as $post)
                            <li>
                                <a href="{{ route('topics.show', $post->topic_id) }}#post-{{ $post->id }}"
                                   class="block p-4 transition-colors hover:bg-surface-sunken">
                                    <span class="block font-semibold text-ink">{{ $post->topic?->title ?? 'Topic' }}</span>
                                    <span class="mt-1 block text-sm text-ink-muted">{{ \Illuminate\Support\Str::limit($post->body_text, 180) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @else
                <x-ui.empty title="No posts matched your search">
                    <x-slot:icon><x-ui.icon name="search" class="h-6 w-6" /></x-slot:icon>
                    Try a different keyword, widen your filters, or check your spelling.
                </x-ui.empty>
            @endif
        @endif
    </x-ui.container>
@endsection
