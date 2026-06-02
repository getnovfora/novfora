{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'System · Service Tier'])

@section('content')
    <main style="max-width:980px;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <nav style="color:#666;font-size:.9rem">Admin → System → Service Tier</nav>
        <h1>Service Tier</h1>
        <p style="color:#555">
            Which deployment tier each capability is running on, and which optional services are reachable.
            The app runs <strong>identically</strong> on both tiers — enhanced services are detected, never
            required (ADR-0003).
        </p>
        <livewire:admin.service-tier />
    </main>
@endsection
