{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Tags · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => 'Tags'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Tags</h1>
        </div>

        @if ($tags->isNotEmpty())
            <x-ui.card>
                <div class="flex flex-wrap gap-3 p-4">
                    @foreach ($tags as $tag)
                        <a href="{{ route('tags.show', $tag) }}"
                           class="inline-flex items-center gap-2 rounded-full border border-line bg-surface-sunken px-3 py-1.5 text-sm font-medium text-ink-muted hover:border-accent hover:text-accent transition-colors"
                           dusk="tag-listing-{{ $tag->id }}">
                            {{ $tag->name }}
                            <span class="nums text-xs text-ink-subtle">{{ number_format($tag->usage_count) }}</span>
                        </a>
                    @endforeach
                </div>
            </x-ui.card>

            <div>{{ $tags->links() }}</div>
        @else
            <x-ui.card flush>
                <x-ui.empty title="No tags yet">
                    <x-slot:icon><x-ui.icon name="tag" class="h-6 w-6" /></x-slot:icon>
                    Tags will appear here once members start tagging their topics.
                </x-ui.empty>
            </x-ui.card>
        @endif
    </x-ui.container>
@endsection
