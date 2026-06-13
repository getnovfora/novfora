{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Members directory'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Members'], ['label' => 'Directory']]" />
@endsection

@section('content')
    <x-admin.shell title="Members directory" description="Control who can view the public members listing.">
        <livewire:admin.settings.members-directory />
    </x-admin.shell>
@endsection
