{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- ACP v3 · v3-a — Security → Admin Manager: grant an individual user a SUBSET of ACP sections (a restricted admin). --}}
@extends('layouts.app', ['title' => 'Admin · Admin Manager'])

@section('content')
    <x-admin.shell title="Admin Manager" description="Grant an individual member a subset of admin sections — a restricted admin. Full admins and co-owners are managed under Groups.">
        <livewire:admin.security.admin-accounts />
    </x-admin.shell>
@endsection
