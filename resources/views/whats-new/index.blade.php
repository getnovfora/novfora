{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => "What's new · ".config('app.name', 'Hearth')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => 'What\'s new'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">What's new</h1>
            <p class="text-sm text-ink-muted">Topics with activity since you last read them.</p>
        </div>

        @if ($topics->isEmpty())
            <x-ui.card>
                <x-ui.empty title="You're all caught up">
                    <x-slot:icon><x-ui.icon name="check" class="h-6 w-6" /></x-slot:icon>
                    Nothing new right now — check back later for fresh activity.
                </x-ui.empty>
            </x-ui.card>
        @else
            <x-ui.card flush>
                <ul class="divide-y divide-line">
                    @foreach ($topics as $topic)
                        <li>
                            <a href="{{ route('topics.show', $topic) }}"
                               class="flex flex-col gap-1 px-4 py-3 hover:bg-surface-sunken sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                                <span class="font-medium text-ink">{{ $topic->title }}</span>
                                <span class="flex flex-wrap items-center gap-x-1.5 text-sm text-ink-muted">
                                    @if ($topic->forum?->title)
                                        <span>{{ $topic->forum->title }}</span>
                                        <span class="text-ink-subtle" aria-hidden="true">·</span>
                                    @endif
                                    <span class="text-ink-subtle">{{ $topic->last_posted_at?->diffForHumans() }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    </x-ui.container>
@endsection
