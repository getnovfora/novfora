{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'System · Service Tier'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => 'System'],
        ['label' => 'Service Tier'],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Service Tier">
        <p class="text-sm text-ink-muted max-w-2xl">
            Which deployment tier each capability is running on, and which optional services are reachable.
            The app runs <strong class="font-semibold text-ink">identically</strong> on both tiers — enhanced
            services are detected, never required (ADR-0003).
        </p>

        <livewire:admin.service-tier />
    </x-admin.shell>
@endsection
