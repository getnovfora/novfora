{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => ($q !== '' ? "Search: {$q}" : 'Search').' · '.config('app.name', 'Hearth')])

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">Search</h1>

        <form method="GET" action="{{ route('search.index') }}" role="search" class="flex flex-col gap-2 sm:flex-row">
            <label for="q" class="sr-only">Search posts</label>
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-subtle">
                    <x-ui.icon name="search" class="h-4 w-4" />
                </span>
                <input id="q" type="search" name="q" value="{{ $q }}" placeholder="Search posts…" autofocus
                       class="w-full min-h-11 pl-9 pr-3 rounded-md bg-surface-raised text-ink placeholder:text-ink-subtle border border-line transition-colors focus:border-accent">
            </div>
            <x-ui.button type="submit">Search</x-ui.button>
        </form>

        @if ($q !== '')
            <p class="text-sm text-ink-muted">
                <span class="nums">{{ $results->count() }}</span>
                {{ \Illuminate\Support\Str::plural('result', $results->count()) }} for “{{ $q }}”
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
                    Try a different keyword or check your spelling.
                </x-ui.empty>
            @endif
        @endif
    </x-ui.container>
@endsection
