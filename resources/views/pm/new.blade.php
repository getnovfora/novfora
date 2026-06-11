{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'New message · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Messages', 'url' => route('pm.inbox')],
        ['label' => 'New message'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">New message</h1>
        <x-ui.card>
            <livewire:pm.new-conversation />
        </x-ui.card>
    </x-ui.container>
@endsection
