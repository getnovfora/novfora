{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Groups'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => 'Groups'],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Member groups">
        <livewire:admin.groups />
    </x-admin.shell>
@endsection
