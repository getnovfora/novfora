{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'System · Upgrade'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => 'System'],
        ['label' => 'Upgrade'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="space-y-1.5">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Upgrade</h1>
            <p class="text-sm text-ink-muted max-w-2xl">
                When you deploy a new release, {{ config('app.name', 'Hearth') }} migrates the database itself —
                no SSH, no manual SQL. With <strong class="font-semibold text-ink">automatic upgrades</strong> on
                (the default), the cron line applies pending migrations behind a brief maintenance page within a
                minute of the upload. Turn it off (<code class="rounded-sm bg-surface-sunken px-1 py-0.5 font-mono text-xs text-ink">HEARTH_AUTO_UPGRADE=false</code>)
                to apply them yourself here instead. See
                <code class="rounded-sm bg-surface-sunken px-1 py-0.5 font-mono text-xs text-ink">docs/getting-started.md</code> §5.
            </p>
        </div>

        <livewire:admin.upgrade />
    </x-ui.container>
@endsection
