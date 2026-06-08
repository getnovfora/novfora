{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · General settings'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Settings'], ['label' => 'General']]" />
@endsection

@section('content')
    <x-admin.shell title="General settings" description="Site identity, a site-wide notice, and the board-offline switch.">
        <livewire:admin.settings.general />
    </x-admin.shell>
@endsection
