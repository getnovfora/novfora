{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Scheduled · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Forums', 'url' => route('forums.index')], ['label' => 'Scheduled']]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">Scheduled replies</h1>
        <p class="text-sm text-ink-muted">Replies you’ve scheduled to publish later. They appear in the topic at their time.</p>

        <livewire:forum.scheduled-posts />
    </x-ui.container>
@endsection
