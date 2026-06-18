{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Groups · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Forums', 'url' => route('forums.index')], ['label' => 'Groups']]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5" dusk="public-groups">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('Groups') }}</h1>
        </div>

        <p class="text-sm text-ink-subtle">{{ __('Public groups you can join or request to join. Only the group name, description, and member count are shown here — membership lists are private.') }}</p>

        @if ($groups->isEmpty())
            <x-ui.card>
                <p class="text-sm text-ink-subtle">{{ __('No public groups yet.') }}</p>
            </x-ui.card>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" dusk="groups-grid">
                @foreach ($groups as $g)
                    <li>
                        <div class="flex h-full flex-col rounded-lg border border-line bg-surface p-4">
                            <div class="flex items-center gap-2">
                                <span class="inline-block h-3 w-3 flex-shrink-0 rounded-full"
                                      @if (\App\Support\GroupColor::cssVar($g->color))
                                          style="background: {{ \App\Support\GroupColor::cssVar($g->color) }}"
                                      @else
                                          style="background: var(--accent)"
                                      @endif
                                ></span>
                                <span class="min-w-0 flex-1 truncate font-semibold text-ink">{{ $g->name }}</span>
                            </div>
                            @if ($g->description)
                                <p class="mt-2 line-clamp-2 text-sm text-ink-subtle">{{ $g->description }}</p>
                            @endif
                            <span class="mt-auto pt-3 text-xs text-ink-subtle">
                                {{ trans_choice(':count member|:count members', (int) $g->users_count, ['count' => number_format((int) $g->users_count)]) }}
                            </span>
                            <div class="mt-3">
                                <livewire:groups.join-button :group="$g" :key="'jb-'.$g->id" />
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.container>
@endsection
