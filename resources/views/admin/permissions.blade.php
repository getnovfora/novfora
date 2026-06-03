{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'System · Permission Inspector'])

@section('content')
    <main style="max-width:980px;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <nav style="color:#666;font-size:.9rem">Admin → System → Permission Inspector</nav>
        <h1>Permission Inspector</h1>
        <p style="color:#555">
            Explain exactly <em>why</em> a user can or cannot do something at a given scope — the verdict,
            the decisive rule, the scope chain that was walked, and every ACL entry that fed the decision
            (security §1.4). This is the same resolution the engine uses at runtime, never a re-implementation.
        </p>
        <livewire:admin.permission-inspector />
    </main>
@endsection
