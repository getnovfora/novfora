{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Members'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => __('admin.sections.members')],
        ['label' => __('admin.nav.all_members')],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Members">
        <livewire:admin.members />
    </x-admin.shell>
@endsection
