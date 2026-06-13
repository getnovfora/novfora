{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Forums · '.config('app.name', 'NovFora')])

@section('content')
    <x-ui.container size="lg" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Forums</h1>
        </div>

        {{-- Configurable layout region (ADR-0032) — admin-placed widgets above the forum list. --}}
        <x-region name="forum_top" />

        @forelse ($tree as $node)
            @if ($node->isCategory())
                <section class="space-y-2">
                    <h2 class="px-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ $node->title }}</h2>
                    @php
                        $visibleForums = collect($node->children)
                            ->filter(fn ($forum) => $viewer->canDo('forum.view', $forum->permissionScope()));
                    @endphp
                    @if ($visibleForums->isNotEmpty())
                        <x-ui.card flush>
                            <div class="divide-y divide-line">
                                @foreach ($visibleForums as $forum)
                                    @include('forum.partials.forum-row', ['forum' => $forum])
                                @endforeach
                            </div>
                        </x-ui.card>
                    @endif
                </section>
            @elseif ($viewer->canDo('forum.view', $node->permissionScope()))
                <x-ui.card flush>
                    <div class="divide-y divide-line">
                        @include('forum.partials.forum-row', ['forum' => $node])
                    </div>
                </x-ui.card>
            @endif
        @empty
            <x-ui.card flush>
                <x-ui.empty title="No forums yet">
                    <x-slot:icon><x-ui.icon name="message" class="h-6 w-6" /></x-slot:icon>
                    Once forums are created, they’ll show up here for everyone to browse.
                </x-ui.empty>
            </x-ui.card>
        @endforelse

        {{-- Configurable layout region (ADR-0032) — admin-placed widgets below the forum list. --}}
        <x-region name="forum_bottom" />

        {{-- Community activity feed (P2-M3): global, per-viewer permission-filtered, cached primitives. --}}
        <livewire:community.activity-feed />
    </x-ui.container>
@endsection
