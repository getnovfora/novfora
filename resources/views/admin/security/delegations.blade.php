{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- ACP v3 · v3-f — Security → Active delegations: a co-owner grants time-boxed, ceiling-bounded capabilities
     (≤ 30 days, auto-expiring) and early-revokes them. The owner tier only (admin.security.access). --}}
@extends('layouts.app', ['title' => 'Admin · Active delegations'])

@section('content')
    <x-admin.shell title="Active delegations" description="Hand an individual a single capability for a bounded window — auto-expiring, never beyond your own access, and revocable at any time.">
        <livewire:admin.security.active-delegations />
    </x-admin.shell>
@endsection
