{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- ACP v3 · v3-c — Forums → (forum) → Permissions: the per-forum-scope card-per-group editor (overrides). --}}
@extends('layouts.app', ['title' => 'Admin · Forum permissions'])

@section('content')
    @php($mode = request()->query('mode') === 'advanced' ? 'advanced' : 'simple')
    <x-admin.shell :title="__('admin.perms.title').' — '.$forum->title">
        <div class="mb-4"><x-admin.perm-mode-switch :mode="$mode" /></div>
        @if ($mode === 'advanced')
            <livewire:permissions.group-editor scope-type="forum" :scope-id="$forum->id" />
        @else
            <livewire:permissions.group-simple-editor scope-type="forum" :scope-id="$forum->id" />
        @endif
    </x-admin.shell>
@endsection
