{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Manage member'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => __('admin.sections.members')],
        ['label' => __('admin.nav.all_members')],
        ['label' => $user->display_name ?? $user->username ?? ('#'.$user->id)],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Manage member">
        <livewire:admin.members.manage :user-id="$user->id" />
    </x-admin.shell>
@endsection
