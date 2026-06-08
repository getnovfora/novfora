{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'System · Audit log'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'System'], ['label' => 'Audit log']]" />
@endsection

@section('content')
    <x-admin.shell title="Audit log">
        <p class="text-sm text-ink-muted max-w-2xl">
            An append-only record of staff and system actions — moderation, bans, settings, upgrades, restores.
            Filter by action, actor, or date. Read-only by design.
        </p>

        <livewire:admin.audit-log />
    </x-admin.shell>
@endsection
