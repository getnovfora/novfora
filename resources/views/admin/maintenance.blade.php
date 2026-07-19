{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'System · Maintenance'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => __('admin.sections.system')],
        ['label' => __('admin.nav.maintenance')],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Maintenance">
        <p class="text-sm text-ink-muted max-w-2xl">
            Operator housekeeping — clear the compiled caches, recompute drifted counters, tail the application
            log, and send a mail self-test. Everything here is safe and reversible; the counter rebuild runs as
            a background job, and with shell access you can also run
            <code class="rounded-sm bg-surface-sunken px-1 py-0.5 font-mono text-xs text-ink">php artisan novfora:forums:recompute-counters</code>.
        </p>

        <livewire:admin.maintenance />
    </x-admin.shell>
@endsection
