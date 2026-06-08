{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Registration settings'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Settings'], ['label' => 'Registration']]" />
@endsection

@section('content')
    <x-admin.shell title="Registration settings" description="Who can sign up, and what they must do to become active.">
        <livewire:admin.settings.registration />
    </x-admin.shell>
@endsection
