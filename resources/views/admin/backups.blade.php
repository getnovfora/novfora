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
    <x-admin.shell title="Backups">
        <p class="text-sm text-ink-muted max-w-2xl">
            Create a backup (database + uploaded files), download one for off-host safekeeping, or
            <strong>restore</strong> one — no SSH needed. Backups also run automatically from the cron
            schedule. Restoring overwrites the current database and files (a pre-restore safety snapshot is
            taken first); the site shows a brief maintenance page while it runs. With shell access you can
            also restore from the command line with
            <code class="rounded-sm bg-surface-sunken px-1 py-0.5 font-mono text-xs text-ink">php artisan novfora:restore &lt;archive&gt;</code>.
        </p>

        <livewire:admin.backups />
    </x-admin.shell>
@endsection
