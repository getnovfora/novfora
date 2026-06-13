{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Themes'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Settings'], ['label' => 'Themes']]" />
@endsection

@section('content')
    <x-admin.shell title="Themes" description="Create and activate visual themes — accent colour and custom CSS — without touching the filesystem.">
        <livewire:admin.settings.themes />
    </x-admin.shell>
@endsection
