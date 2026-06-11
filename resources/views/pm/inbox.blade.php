{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Messages · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Messages'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Messages</h1>
            <x-ui.button :href="route('pm.create')" size="sm">New message</x-ui.button>
        </div>
        <x-ui.card :flush="true">
            <livewire:pm.conversation-list />
        </x-ui.card>
    </x-ui.container>
@endsection
