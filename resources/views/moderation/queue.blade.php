{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Moderation queue · '.config('app.name', 'Hearth')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => 'Moderation', 'url' => route('moderation.dashboard')],
        ['label' => 'Queue'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Moderation queue</h1>
            <p class="text-sm text-ink-muted">
                Content held by the anti-spam layer — new-user posts, flagged words, and suspicious content awaiting review.
            </p>
        </div>

        <x-ui.tabs :items="[
            ['label' => 'Dashboard', 'url' => route('moderation.dashboard')],
            ['label' => 'Queue', 'url' => route('moderation.queue'), 'active' => true, 'count' => $topics->count() + $posts->count()],
            ['label' => 'Reports', 'url' => route('moderation.reports')],
        ]" />

        {{-- Pending topics --}}
        <section class="space-y-2.5">
            <h2 class="text-lg font-semibold text-ink">Pending topics</h2>
            @forelse ($topics as $topic)
                <x-ui.card class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="font-medium text-ink">{{ $topic->title }}</p>
                        <p class="mt-0.5 text-sm text-ink-muted">
                            by {{ $topic->author?->username }} in {{ $topic->forum?->title }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <form method="POST" action="{{ route('topics.approve', $topic->id) }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">
                                <x-ui.icon name="check" class="h-4 w-4" /> Approve
                            </x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('topics.reject', $topic->id) }}">
                            @csrf
                            <x-ui.button type="submit" size="sm" variant="danger-ghost">Reject</x-ui.button>
                        </form>
                    </div>
                </x-ui.card>
            @empty
                <x-ui.card>
                    <x-ui.empty title="No topics awaiting review">
                        <x-slot:icon><x-ui.icon name="check" class="h-6 w-6" /></x-slot:icon>
                        New topics held for moderation will appear here.
                    </x-ui.empty>
                </x-ui.card>
            @endforelse
        </section>

        {{-- Pending posts --}}
        <section class="space-y-2.5">
            <h2 class="text-lg font-semibold text-ink">Pending posts</h2>
            @forelse ($posts as $post)
                <x-ui.card class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="font-medium text-ink">Reply by {{ $post->author?->username }}</p>
                        <p class="mt-0.5 text-sm text-ink-muted">in {{ $post->topic?->title }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <form method="POST" action="{{ route('posts.approve', $post->id) }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">
                                <x-ui.icon name="check" class="h-4 w-4" /> Approve
                            </x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('posts.reject', $post->id) }}">
                            @csrf
                            <x-ui.button type="submit" size="sm" variant="danger-ghost">Reject</x-ui.button>
                        </form>
                    </div>
                </x-ui.card>
            @empty
                <x-ui.card>
                    <x-ui.empty title="No posts awaiting review">
                        <x-slot:icon><x-ui.icon name="message" class="h-6 w-6" /></x-slot:icon>
                        Replies held for moderation will appear here.
                    </x-ui.empty>
                </x-ui.card>
            @endforelse
        </section>
    </x-ui.container>
@endsection
