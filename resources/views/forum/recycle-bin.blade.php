{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => __('forum.recycle_bin').' · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    {{-- Reachable by any signed-in member (list filters to what they may restore); only link the dashboard
         for a viewer its route would admit (NOV-88). --}}
    <x-ui.breadcrumbs :items="[
        (auth()->user()?->canDo('bans.manage', \App\Permissions\Scope::global()) ?? false)
            ? ['label' => 'Moderation', 'url' => route('moderation.dashboard')]
            : ['label' => 'Moderation'],
        ['label' => __('forum.recycle_bin')],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('forum.recycle_bin') }}</h1>
            <p class="mt-1 text-sm text-ink-muted">{{ __('forum.recycle_intro') }}</p>
        </div>

        <section class="space-y-2">
            <h2 class="text-sm font-semibold text-ink">{{ __('forum.deleted_topics') }}</h2>
            <x-ui.card flush>
                @forelse ($topics as $topic)
                    <div class="flex items-center justify-between gap-3 px-4 py-3 @if (! $loop->first) border-t border-line @endif">
                        <span class="text-sm text-ink">{{ $topic->title }}
                            <span class="text-ink-subtle">{{ __('forum.in_x', ['name' => $topic->forum?->title]) }}</span></span>
                        <form method="POST" action="{{ route('topics.restore', $topic->id) }}">@csrf
                            <x-ui.button type="submit" variant="ghost" size="sm">{{ __('forum.restore') }}</x-ui.button>
                        </form>
                    </div>
                @empty
                    <x-ui.empty title="{{ __('forum.empty_deleted_topics_title') }}">{{ __('forum.empty_deleted_topics_body') }}</x-ui.empty>
                @endforelse
            </x-ui.card>
        </section>

        <section class="space-y-2">
            <h2 class="text-sm font-semibold text-ink">{{ __('forum.deleted_posts') }}</h2>
            <x-ui.card flush>
                @forelse ($posts as $post)
                    <div class="flex items-center justify-between gap-3 px-4 py-3 @if (! $loop->first) border-t border-line @endif">
                        <span class="text-sm text-ink">{{ __('forum.post_n', ['id' => $post->id]) }}
                            <span class="text-ink-subtle">{{ __('forum.in_x', ['name' => $post->topic?->title]) }}</span></span>
                        <form method="POST" action="{{ route('posts.restore', $post->id) }}">@csrf
                            <x-ui.button type="submit" variant="ghost" size="sm">{{ __('forum.restore') }}</x-ui.button>
                        </form>
                    </div>
                @empty
                    <x-ui.empty title="{{ __('forum.empty_deleted_posts_title') }}">{{ __('forum.empty_deleted_posts_body') }}</x-ui.empty>
                @endforelse
            </x-ui.card>
        </section>
    </x-ui.container>
@endsection
