{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Payment settings'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Settings'], ['label' => 'Payments']]" />
@endsection

@section('content')
    <x-admin.shell title="Payment settings" description="Stripe hosted checkout for paid memberships. Charging is disabled until you enable it.">
        <livewire:admin.settings.payments />
    </x-admin.shell>
@endsection
