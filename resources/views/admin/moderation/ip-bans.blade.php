{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'IP & range bans'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => __('admin.sections.moderation')],
        ['label' => __('admin.nav.ip_bans')],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="IP & range bans">
        <livewire:admin.moderation.ip-bans />
    </x-admin.shell>
@endsection
