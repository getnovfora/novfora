{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- ACP v3 · v3-d — Groups → Roles: the custom role builder (clusters of Yes/No/Never over the catalog). --}}
@extends('layouts.app', ['title' => 'Admin · Roles'])

@section('content')
    <x-admin.shell :title="__('admin.roles.title')">
        <livewire:admin.roles />
    </x-admin.shell>
@endsection
