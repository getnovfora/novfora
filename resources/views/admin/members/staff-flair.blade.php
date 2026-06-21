{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Staff flair'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Members'], ['label' => 'Staff flair']]" />
@endsection

@section('content')
    <x-admin.shell title="Staff flair" description="Show live staff role badges across the forum and publish the public Team page.">
        <livewire:admin.settings.staff-flair />
    </x-admin.shell>
@endsection
