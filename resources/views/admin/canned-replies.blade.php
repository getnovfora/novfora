{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Canned replies'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => __('admin.sections.moderation')],
        ['label' => __('admin.nav.canned_replies')],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Canned replies">
        <livewire:admin.canned-replies />
    </x-admin.shell>
@endsection
