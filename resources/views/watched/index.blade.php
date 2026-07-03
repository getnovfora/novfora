{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- The Watched surface (U2, NOV-101) — the member home loop: recent activity across followed forums/tags plus
     the three follow lists. Every list is visibility-fenced in WatchedController. --}}
@extends('layouts.app', ['title' => __('watched.title').' · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => __('common.forums'), 'url' => route('forums.index')],
        ['label' => __('watched.title')],
    ]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('watched.title') }}</h1>
            <p class="mt-1 text-sm text-ink-muted">{{ __('watched.intro') }}</p>
        </div>

        @if ($forums->isEmpty() && $tags->isEmpty() && $topics->isEmpty())
            <x-ui.card flush>
                <x-ui.empty title="{{ __('watched.empty_title') }}">
                    <x-slot:icon><x-ui.icon name="bell" class="h-6 w-6" /></x-slot:icon>
                    {{ __('watched.empty_body') }}
                </x-ui.empty>
            </x-ui.card>
        @else
            @if ($recent->isNotEmpty())
                <section aria-labelledby="watched-recent">
                    <h2 id="watched-recent" class="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ __('watched.recent') }}</h2>
                    <x-ui.card flush>
                        <div class="divide-y divide-line">
                            @foreach ($recent as $topic)
                                <div class="p-4 hover:bg-surface-sunken">
                                    <a href="{{ route('topics.show', $topic) }}" class="block font-semibold text-ink hover:text-accent" dusk="watched-recent-{{ $topic->id }}">{{ $topic->title }}</a>
                                    <p class="mt-0.5 text-sm text-ink-muted">
                                        {{ __('watched.in') }} <a href="{{ route('forums.show', $topic->forum) }}" class="text-accent hover:underline">{{ $topic->forum->title }}</a>
                                        · {{ __('forum.by') }} <x-ui.user-name :user="$topic->author" />
                                        @if ($topic->last_posted_at) · <x-ui.timestamp :value="$topic->last_posted_at" class="text-ink-subtle" /> @endif
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                </section>
            @endif

            <div class="grid gap-6 md:grid-cols-2">
                @if ($forums->isNotEmpty())
                    <section aria-labelledby="watched-forums">
                        <h2 id="watched-forums" class="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ __('watched.forums') }}</h2>
                        <x-ui.card flush><div class="divide-y divide-line">
                            @foreach ($forums as $forum)
                                <a href="{{ route('forums.show', $forum) }}" class="flex items-center gap-2 p-3 hover:bg-surface-sunken">
                                    <x-ui.icon name="message" class="h-4 w-4 text-ink-subtle" /> <span class="font-medium text-ink">{{ $forum->title }}</span>
                                </a>
                            @endforeach
                        </div></x-ui.card>
                    </section>
                @endif

                @if ($topics->isNotEmpty())
                    <section aria-labelledby="watched-topics">
                        <h2 id="watched-topics" class="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ __('watched.topics') }}</h2>
                        <x-ui.card flush><div class="divide-y divide-line">
                            @foreach ($topics as $topic)
                                <a href="{{ route('topics.show', $topic) }}" class="block p-3 hover:bg-surface-sunken">
                                    <span class="font-medium text-ink">{{ $topic->title }}</span>
                                    @if ($topic->last_posted_at)<span class="mt-0.5 block text-xs text-ink-subtle"><x-ui.timestamp :value="$topic->last_posted_at" /></span>@endif
                                </a>
                            @endforeach
                        </div></x-ui.card>
                    </section>
                @endif
            </div>

            @if ($tags->isNotEmpty())
                <section aria-labelledby="watched-tags">
                    <h2 id="watched-tags" class="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ __('watched.tags') }}</h2>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($tags as $tag)
                            <x-forum.tag-chip :tag="$tag" />
                        @endforeach
                    </div>
                </section>
            @endif
        @endif
    </x-ui.container>
@endsection
