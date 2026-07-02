{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- metaDescription (U20/ADR-0108): the ACP "Site description / tagline" backs the index's meta/og
     description via the layout seam; empty setting → the tags are omitted entirely. --}}
@extends('layouts.app', [
    'title' => __('common.forums').' · '.config('app.name', 'NovFora'),
    'metaDescription' => app(\App\Settings\Settings::class)->siteView()['site_description'] ?? '',
])

@push('head')
    <link rel="canonical" href="{{ route('forums.index') }}">
    {{-- No-flash Info-Center collapse (mirrors the density/theme no-flash): set data-infocenter="collapsed" on
         <html> BEFORE first paint from the persisted choice, so the CSS below hides the body with no
         expand-then-collapse jump. Alpine reads + clears the attribute on hydrate and owns the animated toggle. --}}
    @php
        $icNonce = \Illuminate\Support\Facades\Vite::cspNonce();
    @endphp
    <script @if ($icNonce) nonce="{{ $icNonce }}" @endif>
        (function () {
            try { if (localStorage.getItem('novfora-infocenter-collapsed') === '1') document.documentElement.setAttribute('data-infocenter', 'collapsed'); } catch (e) {}
        })();
    </script>
    <style @if ($icNonce) nonce="{{ $icNonce }}" @endif>html[data-infocenter="collapsed"] [data-infocenter-body]{display:none}</style>
@endpush

@section('content')
    @php $sidebarHtml = app(\App\Theme\LayoutManager::class)->render('forum_sidebar'); @endphp
    <x-ui.container size="lg" class="space-y-6">
        @if ($sidebarHtml !== '')
        <div class="grid gap-6 lg:grid-cols-[1fr_18rem] lg:items-start">
            <div class="space-y-6 min-w-0">
        @endif
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('common.forums') }}</h1>
        </div>

        {{-- Overridable sandbox template (ADR-0038): a welcome panel, rendered only when an admin enables it. --}}
        <x-sandbox-template name="home_welcome" />

        {{-- Configurable layout region (ADR-0032) — admin-placed widgets above the forum list. --}}
        <x-region name="forum_top" />

        @forelse ($tree as $node)
            @if ($node->isCategory())
                <section class="space-y-2">
                    <h2 class="px-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle font-sans">{{ $node->title }}</h2>
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
                <x-ui.empty title="{{ __('forum.no_forums_title') }}">
                    <x-slot:icon><x-ui.icon name="message" class="h-6 w-6" /></x-slot:icon>
                    {{ __('forum.no_forums_body') }}
                </x-ui.empty>
            </x-ui.card>
        @endforelse

        {{-- Configurable layout region (ADR-0032) — admin-placed widgets below the forum list. --}}
        <x-region name="forum_bottom" />

        {{-- Classic Info Center (ADR-0077): board statistics + opt-in who's-online, above the activity feed. --}}
        @include('forum.partials.info-center')

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
