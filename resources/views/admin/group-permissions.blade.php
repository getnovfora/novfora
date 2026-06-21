{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- ACP v3 · v3-c — Groups → Group permissions: the GLOBAL-scope card-per-group editor (the global defaults). --}}
@extends('layouts.app', ['title' => 'Admin · Group permissions'])

@section('content')
    @php($mode = request()->query('mode') === 'advanced' ? 'advanced' : 'simple')
    <x-admin.shell :title="__('admin.perms.title')">
        <div class="mb-4"><x-admin.perm-mode-switch :mode="$mode" /></div>
        @if ($mode === 'advanced')
            <livewire:permissions.group-editor scope-type="global" />
        @else
            <livewire:permissions.group-simple-editor scope-type="global" />
        @endif
    </x-admin.shell>
@endsection
