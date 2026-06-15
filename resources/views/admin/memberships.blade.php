{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Memberships'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Memberships']]" />
@endsection

@section('content')
    <x-admin.shell title="Memberships" description="Grant or revoke member subscriptions (offline / manual). The only live-granting path in this build.">
        <livewire:admin.member-grants />
    </x-admin.shell>
@endsection
