{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => $club->name.' · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => 'Clubs', 'url' => route('clubs.index')],
        ['label' => $club->name],
    ]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="inline-block h-4 w-4 rounded-full" style="background: {{ $club->color ?? 'var(--accent)' }}"></span>
                        <div>
                            <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $club->name }}</h1>
                            @if ($club->tagline)
                                <p class="text-sm text-ink-subtle">{{ $club->tagline }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($club->privacy !== 'public')
                            <x-ui.badge>{{ $club->privacy === 'closed' ? __('Closed') : __('Private') }}</x-ui.badge>
                        @endif
                        @if ($club->isManageableBy(auth()->user()))
                            <x-ui.button variant="ghost" size="sm" :href="route('clubs.edit', $club)" dusk="club-manage-link">{{ __('Manage') }}</x-ui.button>
                        @endif
                    </div>
                </div>

                @if ($club->description)
                    <p class="whitespace-pre-line text-sm text-ink">{{ $club->description }}</p>
                @endif

                <div class="flex flex-wrap items-center gap-4 text-xs text-ink-subtle">
                    <a href="{{ route('clubs.members', $club) }}" class="hover:text-accent">{{ trans_choice(':count member|:count members', (int) $club->member_count, ['count' => number_format((int) $club->member_count)]) }}</a>
                    @if ($viewerRole)
                        <x-ui.badge>{{ __('You are :role', ['role' => __(ucfirst($viewerRole))]) }}</x-ui.badge>
                    @endif
                </div>

                <livewire:clubs.join-button :club="$club" :key="'join-'.$club->id" />
            </div>
        </x-ui.card>

        {{-- Owners & moderators — always shown on a visible club. Full roster + join flows arrive in M1.3;
             the discussion space arrives in M1.4 (gated by isContentVisibleTo). --}}
        @if ($staff->isNotEmpty())
            <x-ui.card>
                <div class="space-y-2">
                    <h2 class="text-sm font-semibold text-ink">{{ __('Organisers') }}</h2>
                    <ul class="flex flex-wrap gap-3">
                        @foreach ($staff as $m)
                            @if ($m->user)
                                <li class="flex items-center gap-2 text-sm">
                                    <a href="{{ route('profiles.show', $m->user) }}" class="font-medium text-ink hover:text-accent">{{ $m->user->display_name ?? $m->user->username ?? $m->user->name }}</a>
                                    <x-ui.badge>{{ __(ucfirst((string) $m->role)) }}</x-ui.badge>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            </x-ui.card>
        @endif

        @unless ($contentVisible)
            <x-ui.card>
                <p class="text-sm text-ink-subtle">
                    {{ $club->joinPolicy() === 'request'
                        ? __('This club’s discussion is members-only. You can request to join.')
                        : __('This club is invite-only. Its discussion is visible to members.') }}
                </p>
            </x-ui.card>
        @endunless
    </x-ui.container>
@endsection
