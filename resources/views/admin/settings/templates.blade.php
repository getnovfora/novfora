{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Templates'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Settings'], ['label' => 'Templates']]" />
@endsection

@section('content')
    <x-admin.shell title="Templates" description="Customise parts of the site with a small, safe, sandboxed template language — no PHP, no scripts, values auto-escaped.">
        <livewire:admin.settings.templates />
    </x-admin.shell>
@endsection
