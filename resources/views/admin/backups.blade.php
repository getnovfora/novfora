{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'System · Backups'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => 'System'],
        ['label' => 'Backups'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="space-y-1.5">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Backups</h1>
            <p class="text-sm text-ink-muted max-w-2xl">
                Create a backup (database + uploaded files) and download or restore it. Backups also run
                automatically from the cron schedule. To restore, download an archive and run
                <code class="rounded-sm bg-surface-sunken px-1 py-0.5 font-mono text-xs text-ink">php artisan hearth:restore &lt;archive&gt;</code>
                on the host.
            </p>
        </div>

        <livewire:admin.backups />
    </x-ui.container>
@endsection
