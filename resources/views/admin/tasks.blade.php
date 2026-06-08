{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'System · Tasks'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'System'], ['label' => 'Tasks']]" />
@endsection

@section('content')
    <x-admin.shell title="Scheduled tasks">
        <p class="text-sm text-ink-muted max-w-2xl">
            Everything the single cron line drives. One <code class="rounded-sm bg-surface-sunken px-1 py-0.5 font-mono text-xs text-ink">schedule:run</code>
            every minute runs these; “Last run” is shown where a heartbeat or state file records it.
        </p>

        <x-ui.card flush>
            <div class="hidden sm:grid grid-cols-[1fr_2fr_8rem_10rem] gap-3 px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
                <span>Task</span>
                <span>What it does</span>
                <span>Cadence</span>
                <span class="text-right">Last run</span>
            </div>
            <div class="divide-y divide-line">
                @foreach ($tasks as $task)
                    <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[1fr_2fr_8rem_10rem] sm:items-center sm:gap-3 sm:px-5">
                        <span class="font-medium text-ink">{{ $task['name'] }}</span>
                        <span class="text-sm text-ink-muted">{{ $task['detail'] }}</span>
                        <span><x-ui.badge variant="neutral">{{ $task['cadence'] }}</x-ui.badge></span>
                        <span class="text-sm text-ink-muted sm:text-right">
                            @if ($task['last'])
                                <time datetime="{{ \Illuminate\Support\Carbon::createFromTimestamp($task['last'])->toIso8601String() }}">{{ \Illuminate\Support\Carbon::createFromTimestamp($task['last'])->diffForHumans() }}</time>
                            @else
                                <span class="text-ink-subtle">—</span>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </x-admin.shell>
@endsection
