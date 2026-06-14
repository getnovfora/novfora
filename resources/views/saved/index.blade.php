{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Saved · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Forums', 'url' => route('forums.index')], ['label' => 'Saved']]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">Saved</h1>

        <x-ui.card flush>
            <ul class="divide-y divide-line">
                @forelse ($items as $item)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5 text-sm">
                        <x-ui.badge>{{ $item['kind'] }}</x-ui.badge>
                        <a href="{{ $item['url'] }}" class="min-w-0 flex-1 truncate font-medium text-ink hover:text-accent">{{ $item['title'] }}</a>
                        <span class="text-xs text-ink-subtle">{{ $item['saved_at']?->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">
                        Nothing saved yet. Use <strong>Save</strong> on a topic or post to keep it here.
                    </li>
                @endforelse
            </ul>
        </x-ui.card>

        @if ($page->hasPages())
            <div>{{ $page->links() }}</div>
        @endif
    </x-ui.container>
@endsection
