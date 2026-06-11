{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => ($conversation->subject ?? 'Conversation').' · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Messages', 'url' => route('pm.inbox')],
        ['label' => $conversation->subject ?? 'Conversation'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <x-ui.card>
            <livewire:pm.conversation :conversation-id="$conversation->id" />
        </x-ui.card>
    </x-ui.container>
@endsection
