{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Badges'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => 'Badges'],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Badges">
        <livewire:admin.badges />
    </x-admin.shell>
@endsection
