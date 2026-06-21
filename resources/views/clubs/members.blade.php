{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => $club->name.' members · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Clubs', 'url' => route('clubs.index')],
        ['label' => $club->name, 'url' => route('clubs.show', $club)],
        ['label' => 'Members'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $club->name }} — {{ __('members') }}</h1>
        <livewire:clubs.roster :club="$club" :key="'roster-'.$club->id" />
    </x-ui.container>
@endsection
