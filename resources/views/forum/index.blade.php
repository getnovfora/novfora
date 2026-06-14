{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Forums · '.config('app.name', 'NovFora')])

@push('head')
    <link rel="canonical" href="{{ route('forums.index') }}">
@endpush

@section('content')
    @php $sidebarHtml = app(\App\Theme\LayoutManager::class)->render('forum_sidebar'); @endphp
    <x-ui.container size="lg" class="space-y-6">
        @if ($sidebarHtml !== '')
        <div class="grid gap-6 lg:grid-cols-[1fr_18rem] lg:items-start">
            <div class="space-y-6 min-w-0">
        @endif
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Forums</h1>
        </div>

        {{-- Overridable sandbox template (ADR-0038): a welcome panel, rendered only when an admin enables it. --}}
        <x-sandbox-template name="home_welcome" />

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
        @if ($sidebarHtml !== '')
            </div>
            {{-- Theme Studio 1.3: configurable sidebar (admin-placed widgets); only shown when filled. --}}
            <aside class="space-y-3 lg:sticky lg:top-20" data-region="forum_sidebar" aria-label="Sidebar">{!! $sidebarHtml !!}</aside>
        </div>
        @endif
    </x-ui.container>
@endsection
