{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Clubs · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Clubs']]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('Clubs') }}</h1>
            @if ($canCreate)
                <x-ui.button :href="route('clubs.create')" dusk="clubs-create-link">{{ __('Create a club') }}</x-ui.button>
            @endif
        </div>

        <p class="text-sm text-ink-subtle">{{ __('Sub-communities with their own discussion space. Public clubs are open to everyone; closed and private clubs keep their content to members.') }}</p>

        @if ($clubs->isEmpty())
            <x-ui.card>
                <p class="text-sm text-ink-subtle">{{ __('No clubs yet.') }}</p>
            </x-ui.card>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" dusk="clubs-grid">
                @foreach ($clubs as $club)
                    <li>
                        <a href="{{ route('clubs.show', $club) }}"
                           class="flex h-full flex-col rounded-lg border border-line bg-surface p-4 transition hover:border-accent">
                            <div class="flex items-center gap-2">
                                <span class="inline-block h-3 w-3 rounded-full"
                                      style="background: {{ $club->color ?? 'var(--accent)' }}"></span>
                                <span class="min-w-0 flex-1 truncate font-semibold text-ink">{{ $club->name }}</span>
                                @if ($club->privacy !== 'public')
                                    <x-ui.badge>{{ $club->privacy === 'closed' ? __('Closed') : __('Private') }}</x-ui.badge>
                                @endif
                            </div>
                            @if ($club->tagline)
                                <p class="mt-2 line-clamp-2 text-sm text-ink-subtle">{{ $club->tagline }}</p>
                            @endif
                            <span class="mt-auto pt-3 text-xs text-ink-subtle">
                                {{ trans_choice(':count member|:count members', (int) $club->member_count, ['count' => number_format((int) $club->member_count)]) }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if ($clubs->hasPages())
                <div>{{ $clubs->links() }}</div>
            @endif
        @endif
    </x-ui.container>
@endsection
