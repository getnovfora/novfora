{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Reports · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => 'Moderation', 'url' => route('moderation.dashboard')],
        ['label' => 'Reports'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Reports</h1>
            <p class="text-sm text-ink-muted">Open reports from the community, awaiting a moderator.</p>
        </div>

        <x-ui.tabs :items="[
            ['label' => 'Dashboard', 'url' => route('moderation.dashboard')],
            ['label' => 'Queue', 'url' => route('moderation.queue')],
            ['label' => 'Reports', 'url' => route('moderation.reports'), 'active' => true],
        ]" />

        <div class="space-y-2.5">
            @forelse ($reports as $report)
                <x-ui.card class="space-y-2">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="font-medium text-ink">
                                {{ class_basename($report->reportable_type) }}
                                <span class="text-ink-muted nums">#{{ $report->reportable_id }}</span>
                            </p>
                            <p class="mt-0.5 text-sm text-ink-muted">
                                reported by <x-ui.user-name :user="$report->reporter" fallback="system" />
                            </p>
                        </div>
                        <form method="POST" action="{{ route('reports.resolve', $report->id) }}"
                              class="flex shrink-0 items-center gap-2">
                            @csrf
                            <x-ui.button type="submit" name="action" value="resolved" size="sm">
                                <x-ui.icon name="check" class="h-4 w-4" /> Resolve
                            </x-ui.button>
                            <x-ui.button type="submit" name="action" value="dismissed" size="sm" variant="ghost">Dismiss</x-ui.button>
                        </form>
                    </div>
                    @if ($report->reason)
                        <p class="rounded-md bg-surface-sunken px-3 py-2 text-sm text-ink-muted">{{ $report->reason }}</p>
                    @endif
                </x-ui.card>
            @empty
                <x-ui.card>
                    <x-ui.empty title="No open reports">
                        <x-slot:icon><x-ui.icon name="flag" class="h-6 w-6" /></x-slot:icon>
                        Everything is clear — new reports from the community will show up here.
                    </x-ui.empty>
                </x-ui.card>
            @endforelse
        </div>

        <div>{{ $reports->links() }}</div>
    </x-ui.container>
@endsection
