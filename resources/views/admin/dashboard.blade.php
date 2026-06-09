{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Dashboard'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Dashboard']]" />
@endsection

@section('content')
    <x-admin.shell title="Dashboard" description="Everything you need to run the community, in one place.">
        @php
            $schema = $health['schema'];
            // The schema/upgrade chip: stuck > upgrading > pending > up-to-date.
            $schemaChip = $schema['stuck']
                ? ['danger', 'Upgrade held']
                : ($schema['upgrading']
                    ? ['warn', 'Upgrading…']
                    : (($schema['pending'] ?? false)
                        ? ['accent', 'Migrations pending']
                        : ['success', 'Up to date']));

            $queue = $health['queue'];
            $queueState = $queue['ok'] === null ? 'idle' : ($queue['ok'] ? 'ok' : 'warn');
            $queueLabel = $queue['age'] === null
                ? 'Awaiting first run'
                : 'Drained '.\Illuminate\Support\Carbon::now()->subSeconds((int) $queue['age'])->diffForHumans(['short' => true]);

            $restore = $health['restore'];
            $restoreState = $restore['stuck'] ? 'bad' : ($restore['running'] ? 'warn' : 'ok');
            $restoreLabel = $restore['stuck'] ? 'Held for attention' : ($restore['running'] ? 'Running…' : 'Idle');

            $backupAge = $health['backup_age'];

            $strip = [
                ['Database', $health['database']['ok'] ? 'ok' : 'bad', $health['database']['ok'] ? 'Connected' : 'Unreachable'],
                ['Cache', $health['cache']['ok'] ? 'ok' : 'bad', $health['cache']['ok'] ? 'Working' : 'Failing'],
                ['Queue (cron)', $queueState, $queueLabel],
                ['Schema', $schema['stuck'] ? 'bad' : (($schema['pending'] ?? false) || $schema['upgrading'] ? 'warn' : 'ok'), $schemaChip[1]],
                ['Backups', $backupAge === null ? 'warn' : 'ok', $backupAge === null ? 'None yet' : 'Last '.\Illuminate\Support\Carbon::now()->subSeconds($backupAge)->diffForHumans(['short' => true])],
                ['Restore', $restoreState, $restoreLabel],
                ['Service tier', 'ok', ucfirst($health['tier'])],
            ];
            $dot = fn (string $s): string => match ($s) {
                'ok' => 'bg-success', 'warn' => 'bg-warn', 'bad' => 'bg-danger', default => 'bg-ink-subtle',
            };
        @endphp

        {{-- 1 — Pending actions: things the operator may need to act on right now. --}}
        <div class="grid gap-3 sm:grid-cols-3">
            <a href="{{ route('moderation.queue') }}"
               class="flex items-center justify-between gap-3 rounded-lg border border-line bg-surface-raised p-4 shadow-sm hover:bg-surface-sunken">
                <span class="flex items-center gap-2.5 text-sm font-medium text-ink">
                    <x-ui.icon name="check-circle" class="h-5 w-5 text-ink-muted" /> Approval queue
                </span>
                <span class="nums text-xl font-semibold {{ $pending['queue'] > 0 ? 'text-warn' : 'text-ink-muted' }}">{{ $pending['queue'] }}</span>
            </a>
            <a href="{{ route('moderation.reports') }}"
               class="flex items-center justify-between gap-3 rounded-lg border border-line bg-surface-raised p-4 shadow-sm hover:bg-surface-sunken">
                <span class="flex items-center gap-2.5 text-sm font-medium text-ink">
                    <x-ui.icon name="flag" class="h-5 w-5 text-ink-muted" /> Open reports
                </span>
                <span class="nums text-xl font-semibold {{ $pending['reports'] > 0 ? 'text-warn' : 'text-ink-muted' }}">{{ $pending['reports'] }}</span>
            </a>
            <a href="{{ route('admin.system.upgrade') }}"
               class="flex items-center justify-between gap-3 rounded-lg border border-line bg-surface-raised p-4 shadow-sm hover:bg-surface-sunken">
                <span class="flex items-center gap-2.5 text-sm font-medium text-ink">
                    <x-ui.icon name="arrow-up" class="h-5 w-5 text-ink-muted" /> Schema
                </span>
                <x-ui.badge :variant="$schemaChip[0]">{{ $schemaChip[1] }}</x-ui.badge>
            </a>
        </div>

        {{-- 2 — Community stat cards. --}}
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ([['Members', $stats['members'], 'users'], ['Topics', $stats['topics'], 'message'], ['Posts', $stats['posts'], 'inbox']] as [$label, $value, $icon])
                <x-ui.card>
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">
                        <x-ui.icon :name="$icon" class="h-4 w-4" /> {{ $label }}
                    </div>
                    <div class="mt-1 nums text-3xl font-semibold text-ink">{{ number_format($value) }}</div>
                </x-ui.card>
            @endforeach
        </div>

        {{-- 3 — Health strip (same internals as GET /health, read in-process). --}}
        <x-ui.card>
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-ink">System health</h2>
                <a href="{{ route('health') }}" class="text-xs text-ink-subtle hover:text-ink">/health</a>
            </div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($strip as [$label, $state, $detail])
                    <div class="flex items-start gap-2.5">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $dot($state) }}"></span>
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-ink">{{ $label }}</div>
                            <div class="text-xs text-ink-muted truncate">{{ $detail }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        {{-- 4 — Recent audit entries. --}}
        <x-ui.card flush>
            <div class="flex items-center justify-between border-b border-line px-4 py-3 sm:px-5">
                <h2 class="text-sm font-semibold text-ink">Recent activity</h2>
                @if (Route::has('admin.system.audit'))
                    <a href="{{ route('admin.system.audit') }}" class="text-xs text-accent hover:text-accent-hover">View audit log</a>
                @endif
            </div>
            @forelse ($recentAudit as $entry)
                <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-2.5 last:border-b-0 sm:px-5">
                    <div class="min-w-0">
                        <code class="font-mono text-sm text-ink">{{ $entry->action }}</code>
                        <span class="text-sm text-ink-muted"> · <x-ui.user-name :user="$entry->actor" fallback="system" /></span>
                    </div>
                    <time class="shrink-0 text-xs text-ink-subtle" datetime="{{ optional($entry->created_at)->toIso8601String() }}">
                        {{ optional($entry->created_at)->diffForHumans() }}
                    </time>
                </div>
            @empty
                <p class="px-4 py-6 text-sm text-ink-subtle sm:px-5">No activity recorded yet.</p>
            @endforelse
        </x-ui.card>
    </x-admin.shell>
@endsection
