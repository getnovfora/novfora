{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Search settings'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Settings'], ['label' => 'Search']]" />
@endsection

@section('content')
    <x-admin.shell title="Search settings" description="Choose the search engine. Database is the baseline; Meilisearch is an opt-in upgrade.">
        <livewire:admin.settings.search />
    </x-admin.shell>
@endsection
