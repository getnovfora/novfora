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
    <x-admin.shell title="Upgrade">
        <p class="text-sm text-ink-muted max-w-2xl">
            Co-owners with two-factor authentication can check for a newer stable release and queue an upgrade.
            Cron downloads and verifies the signed release, takes a backup, and applies it behind a brief
            maintenance page. Keep cron running until the upgrade finishes.
        </p>
        <p class="text-sm text-ink-muted max-w-2xl">
            When you deploy a new release, {{ config('app.name', 'NovFora') }} migrates the database itself —
            no SSH, no manual SQL. With <strong class="font-semibold text-ink">automatic upgrades</strong> on
            (the default), the cron line applies pending migrations behind a brief maintenance page within a
            minute of the upload. Turn it off (<code class="rounded-sm bg-surface-sunken px-1 py-0.5 font-mono text-xs text-ink">NOVFORA_AUTO_UPGRADE=false</code>)
            to apply them yourself here instead. See
            <code class="rounded-sm bg-surface-sunken px-1 py-0.5 font-mono text-xs text-ink">docs/getting-started.md</code> §5.
        </p>

        <livewire:admin.upgrade />
    </x-admin.shell>
@endsection
