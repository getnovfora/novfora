{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Appearance settings'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Settings'], ['label' => 'Appearance']]" />
@endsection

@section('content')
    <x-admin.shell title="Appearance settings" description="Theme, accent, width, and the look visitors get by default.">
        <livewire:admin.settings.appearance />
    </x-admin.shell>
@endsection
