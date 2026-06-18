{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- ACP v3 · v3-e — Admin → Members → (member) → Primary group: set, lock, or clear the member's primary group. --}}
@extends('layouts.app', ['title' => 'Admin · Primary group · '.$user->display_name ?? $user->username])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => 'Members'],
        ['label' => $user->display_name ?? $user->username],
        ['label' => 'Primary group'],
    ]" />
@endsection

@section('content')
    <x-admin.shell
        title="Primary group — {{ $user->display_name ?? $user->username }}"
        description="Override which group is displayed as this member's rank badge and name colour. Setting a primary group here locks it; the member cannot change it until you clear the lock.">
        <livewire:admin.members.edit-primary-group :user-id="$user->id" />
    </x-admin.shell>
@endsection
