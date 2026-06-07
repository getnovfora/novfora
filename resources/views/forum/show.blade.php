{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => $forum->title.' · '.config('app.name', 'Hearth')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => $forum->title],
    ]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $forum->title }}</h1>
                @if ($forum->description)
                    <p class="mt-1 text-sm text-ink-muted">{{ $forum->description }}</p>
                @endif
            </div>
            @if ($canPost)
                <x-ui.button :href="route('topics.create', $forum)">
                    <x-ui.icon name="plus" class="h-4 w-4" /> New topic
                </x-ui.button>
            @endif
        </div>

        @if ($topics->isNotEmpty())
            <x-ui.card flush>
                <div class="divide-y divide-line">
                    @foreach ($topics as $topic)
                        <div class="flex items-start gap-3 p-4 hover:bg-surface-sunken">
                            <x-ui.avatar :user="$topic->author" size="md" class="mt-0.5 hidden sm:inline-flex" />

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($topic->is_pinned)
                                        <x-ui.badge variant="accent">
                                            <x-ui.icon name="pin" class="h-3.5 w-3.5" /> Pinned
                                        </x-ui.badge>
                                    @endif
                                    @if ($topic->status === 'locked')
                                        <x-ui.badge variant="neutral">
                                            <x-ui.icon name="lock" class="h-3.5 w-3.5" /> Locked
                                        </x-ui.badge>
                                    @endif
                                </div>

                                <a href="{{ route('topics.show', $topic) }}" class="mt-0.5 block font-semibold text-ink hover:text-accent">{{ $topic->title }}</a>

                                <p class="mt-0.5 text-sm text-ink-muted">
                                    by {{ $topic->author?->username ?? 'unknown' }}
                                    @if ($topic->last_posted_at)
                                        <span class="text-ink-subtle">· {{ $topic->last_posted_at->diffForHumans() }}</span>
                                    @endif
                                </p>

                                {{-- Stacked counts at 360px; the inline-right block takes over from sm: up. --}}
                                <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-subtle sm:hidden">
                                    <div class="flex items-center gap-1">
                                        <dt class="sr-only">Replies</dt>
                                        <dd class="nums font-medium text-ink-muted">{{ number_format($topic->reply_count) }}</dd>
                                        <span>replies</span>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <dt class="sr-only">Views</dt>
                                        <dd class="nums font-medium text-ink-muted">{{ number_format($topic->view_count) }}</dd>
                                        <span>views</span>
                                    </div>
                                </dl>
                            </div>

                            <dl class="hidden shrink-0 text-right text-xs text-ink-subtle sm:block">
                                <div class="flex items-baseline justify-end gap-1">
                                    <dd class="nums font-semibold text-ink-muted">{{ number_format($topic->reply_count) }}</dd>
                                    <dt>replies</dt>
                                </div>
                                <div class="mt-0.5 flex items-baseline justify-end gap-1">
                                    <dd class="nums font-semibold text-ink-muted">{{ number_format($topic->view_count) }}</dd>
                                    <dt>views</dt>
                                </div>
                            </dl>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            <div>{{ $topics->links() }}</div>
        @else
            <x-ui.card flush>
                <x-ui.empty title="No topics here yet">
                    <x-slot:icon><x-ui.icon name="message" class="h-6 w-6" /></x-slot:icon>
                    @if ($canPost)
                        Be the first to start the conversation in this forum.
                        <div class="mt-4">
                            <x-ui.button :href="route('topics.create', $forum)">
                                <x-ui.icon name="plus" class="h-4 w-4" /> Start a topic
                            </x-ui.button>
                        </div>
                    @else
                        Check back soon — there’s nothing posted here right now.
                    @endif
                </x-ui.empty>
            </x-ui.card>
        @endif
    </x-ui.container>
@endsection
