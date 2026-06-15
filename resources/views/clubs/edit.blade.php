{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Manage '.$club->name.' · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => 'Clubs', 'url' => route('clubs.index')],
        ['label' => $club->name, 'url' => route('clubs.show', $club)],
        ['label' => 'Manage'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('Manage club') }}</h1>
        <livewire:clubs.edit :club="$club" />
    </x-ui.container>
@endsection
