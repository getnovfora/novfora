{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Moderation defaults'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Settings'], ['label' => 'Moderation']]" />
@endsection

@section('content')
    <x-admin.shell title="Moderation defaults" description="New-user holds, content suspicion, and flood limits.">
        <livewire:admin.settings.moderation />
    </x-admin.shell>
@endsection
