{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Saved searches · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Forums', 'url' => route('forums.index')], ['label' => 'Saved searches']]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">Saved searches</h1>

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        <x-ui.card flush>
            <ul class="divide-y divide-line">
                @forelse ($searches as $search)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5 text-sm">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('search.index').($search->query_string !== '' ? '?'.$search->query_string : '') }}"
                               class="block truncate font-medium text-ink hover:text-accent">{{ $search->name }}</a>
                            @if ($search->term !== '')
                                <p class="truncate text-xs text-ink-subtle">“{{ $search->term }}”</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('saved-searches.destroy', $search->id) }}">
                            @csrf @method('DELETE')
                            <x-ui.button type="submit" variant="danger-ghost" size="sm" dusk="delete-saved-search-{{ $search->id }}">Delete</x-ui.button>
                        </form>
                    </li>
                @empty
                    <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">
                        No saved searches yet. Run a search, then use <strong>Save this search</strong>.
                    </li>
                @endforelse
            </ul>
        </x-ui.card>
    </x-ui.container>
@endsection
