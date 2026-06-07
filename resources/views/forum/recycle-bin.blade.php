{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Recycle bin · '.config('app.name', 'Hearth')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Moderation', 'url' => route('moderation.dashboard')],
        ['label' => 'Recycle bin'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Recycle bin</h1>
            <p class="mt-1 text-sm text-ink-muted">Soft-deleted content you can restore. Hard purge is a separate, audited maintenance job.</p>
        </div>

        <section class="space-y-2">
            <h2 class="text-sm font-semibold text-ink">Deleted topics</h2>
            <x-ui.card flush>
                @forelse ($topics as $topic)
                    <div class="flex items-center justify-between gap-3 px-4 py-3 @if (! $loop->first) border-t border-line @endif">
                        <span class="text-sm text-ink">{{ $topic->title }}
                            <span class="text-ink-subtle">in {{ $topic->forum?->title }}</span></span>
                        <form method="POST" action="{{ route('topics.restore', $topic->id) }}">@csrf
                            <x-ui.button type="submit" variant="ghost" size="sm">Restore</x-ui.button>
                        </form>
                    </div>
                @empty
                    <x-ui.empty title="No deleted topics">Topics you remove will rest here until restored or purged.</x-ui.empty>
                @endforelse
            </x-ui.card>
        </section>

        <section class="space-y-2">
            <h2 class="text-sm font-semibold text-ink">Deleted posts</h2>
            <x-ui.card flush>
                @forelse ($posts as $post)
                    <div class="flex items-center justify-between gap-3 px-4 py-3 @if (! $loop->first) border-t border-line @endif">
                        <span class="text-sm text-ink">Post #{{ $post->id }}
                            <span class="text-ink-subtle">in {{ $post->topic?->title }}</span></span>
                        <form method="POST" action="{{ route('posts.restore', $post->id) }}">@csrf
                            <x-ui.button type="submit" variant="ghost" size="sm">Restore</x-ui.button>
                        </form>
                    </div>
                @empty
                    <x-ui.empty title="No deleted posts">Removed posts can be restored from here.</x-ui.empty>
                @endforelse
            </x-ui.card>
        </section>
    </x-ui.container>
@endsection
