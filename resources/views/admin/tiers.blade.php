{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Membership tiers'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Membership tiers']]" />
@endsection

@section('content')
    <x-admin.shell title="Membership tiers" description="Define membership levels and the perks they grant. No money is charged here.">
        <livewire:admin.tiers />
    </x-admin.shell>
@endsection
