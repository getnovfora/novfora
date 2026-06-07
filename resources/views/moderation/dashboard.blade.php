{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Moderator control panel · '.config('app.name', 'Hearth')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => 'Moderation'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Moderator control panel</h1>
            <p class="text-sm text-ink-muted">
                The anti-spam &amp; moderation baseline (ADR-0007). Trust-level gating, content scanning, and rate
                limits run automatically; the rest lives here.
            </p>
        </div>

        <x-ui.tabs :items="[
            ['label' => 'Dashboard', 'url' => route('moderation.dashboard'), 'active' => true],
            ['label' => 'Queue', 'url' => route('moderation.queue'), 'count' => $counts['pending_topics'] + $counts['pending_posts']],
            ['label' => 'Reports', 'url' => route('moderation.reports'), 'count' => $counts['open_reports']],
        ]" />

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <a href="{{ route('moderation.queue') }}"
               class="group flex items-start gap-3 rounded-lg border border-line bg-surface-raised p-4 shadow-sm transition-colors hover:border-line-strong hover:bg-surface-sunken">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-accent-soft text-accent-soft-ink">
                    <x-ui.icon name="inbox" class="h-5 w-5" />
                </span>
                <span class="min-w-0">
                    <span class="block font-medium text-ink group-hover:text-accent">Approval queue</span>
                    <span class="mt-0.5 block text-sm text-ink-muted nums">{{ $counts['pending_topics'] + $counts['pending_posts'] }} item(s) awaiting review</span>
                </span>
            </a>

            <a href="{{ route('moderation.reports') }}"
               class="group flex items-start gap-3 rounded-lg border border-line bg-surface-raised p-4 shadow-sm transition-colors hover:border-line-strong hover:bg-surface-sunken">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-warn-soft text-warn-ink">
                    <x-ui.icon name="flag" class="h-5 w-5" />
                </span>
                <span class="min-w-0">
                    <span class="block font-medium text-ink group-hover:text-accent">Reports</span>
                    <span class="mt-0.5 block text-sm text-ink-muted nums">{{ $counts['open_reports'] }} open report(s)</span>
                </span>
            </a>

            <a href="{{ route('moderation.recycle-bin') }}"
               class="group flex items-start gap-3 rounded-lg border border-line bg-surface-raised p-4 shadow-sm transition-colors hover:border-line-strong hover:bg-surface-sunken sm:col-span-2">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-surface-sunken text-ink-muted">
                    <x-ui.icon name="arrow-left" class="h-5 w-5" />
                </span>
                <span class="min-w-0">
                    <span class="block font-medium text-ink group-hover:text-accent">Recycle bin</span>
                    <span class="mt-0.5 block text-sm text-ink-muted">Restore soft-deleted content</span>
                </span>
            </a>
        </div>
    </x-ui.container>
@endsection
