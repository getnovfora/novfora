{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Pending members'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => __('admin.sections.members')],
        ['label' => __('admin.nav.pending_members')],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Pending members">
        <livewire:admin.members.pending />
    </x-admin.shell>
@endsection
