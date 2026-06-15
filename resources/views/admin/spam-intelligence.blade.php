{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Spam intelligence'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => 'Spam intelligence']]" />
@endsection

@section('content')
    <x-admin.shell title="Spam intelligence" description="Review posts the spam scorer held, with their scores and signals.">
        <livewire:admin.spam-intelligence />
    </x-admin.shell>
@endsection
