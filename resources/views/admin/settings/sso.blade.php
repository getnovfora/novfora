{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Social login (SSO)'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Settings'], ['label' => 'Social login']]" />
@endsection

@section('content')
    <x-admin.shell title="Social login (SSO)" description="OAuth sign-in with Google, GitHub, and Discord. Off by default.">
        <livewire:admin.settings.sso />
    </x-admin.shell>
@endsection
