{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Warning types'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => __('admin.sections.moderation')],
        ['label' => __('admin.nav.warning_types')],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Warning types">
        <livewire:admin.warning-types />
    </x-admin.shell>
@endsection
