{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'System · Permission Inspector'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => 'System'],
        ['label' => 'Permission Inspector'],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Permission Inspector">
        <p class="text-sm text-ink-muted max-w-2xl">
            Explain exactly <em>why</em> a user can or cannot do something at a given scope — the verdict,
            the decisive rule, the scope chain that was walked, and every ACL entry that fed the decision
            (security §1.4). This is the same resolution the engine uses at runtime, never a re-implementation.
        </p>

        <livewire:admin.permission-inspector />
    </x-admin.shell>
@endsection
