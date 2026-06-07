{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Your warnings · '.config('app.name', 'Hearth')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => 'Your warnings'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Your warnings</h1>
            <p class="text-sm text-ink-muted">
                Please read and acknowledge any active warning — posting is restored once all are acknowledged.
            </p>
        </div>

        @if ($warnings->isEmpty())
            <x-ui.card>
                <x-ui.empty title="You have no warnings">
                    <x-slot:icon><x-ui.icon name="shield" class="h-6 w-6" /></x-slot:icon>
                    Your account is in good standing.
                </x-ui.empty>
            </x-ui.card>
        @else
            <x-ui.card flush>
                <ul class="divide-y divide-line">
                    @foreach ($warnings as $warning)
                        <li class="px-4 py-4 space-y-2">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0 space-y-1">
                                    <p class="font-medium text-ink">{{ $warning->type?->label ?? 'Warning' }}</p>
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-muted">
                                        <span class="nums">{{ $warning->points }} pts</span>
                                        @if ($warning->expires_at)
                                            <span class="text-ink-subtle" aria-hidden="true">·</span>
                                            <span>expires {{ $warning->expires_at->toFormattedDateString() }}</span>
                                        @endif
                                    </p>
                                </div>
                                <div class="shrink-0">
                                    @if ($warning->acknowledged_at)
                                        <x-ui.badge variant="success">
                                            <x-ui.icon name="check" class="h-3.5 w-3.5" /> Acknowledged
                                        </x-ui.badge>
                                    @else
                                        <form method="POST" action="{{ route('warnings.acknowledge', $warning->id) }}">
                                            @csrf
                                            <x-ui.button type="submit" variant="ghost" size="sm">Acknowledge</x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                            @if ($warning->reason)
                                <p class="text-sm text-ink-muted">{{ $warning->reason }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    </x-ui.container>
@endsection
