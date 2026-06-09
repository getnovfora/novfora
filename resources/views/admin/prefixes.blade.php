{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Topic Prefixes'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => 'Prefixes'],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Topic prefixes">
        <livewire:admin.prefixes />
    </x-admin.shell>
@endsection
