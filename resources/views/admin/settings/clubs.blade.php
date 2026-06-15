{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Club settings'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Settings'], ['label' => 'Clubs']]" />
@endsection

@section('content')
    <x-admin.shell title="Club settings" description="Control who may create clubs (sub-communities).">
        <livewire:admin.settings.clubs />
    </x-admin.shell>
@endsection
