{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- ACP v3 · v3-a — Security → Co-owners: appoint/remove co-owners (the top admin tier), last-owner-guarded. --}}
@extends('layouts.app', ['title' => 'Admin · Co-owners'])

@section('content')
    <x-admin.shell title="Co-owners" description="The top admin tier — multiple owners who administer admins and each other. The last co-owner can never be removed.">
        <livewire:admin.security.co-owners />
    </x-admin.shell>
@endsection
