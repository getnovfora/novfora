{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Email settings'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Settings'], ['label' => 'Email']]" />
@endsection

@section('content')
    <x-admin.shell title="Email settings" description="How the forum sends mail — and a button to test that it works.">
        <livewire:admin.settings.email />
    </x-admin.shell>
@endsection
