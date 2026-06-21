{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Create a club · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Clubs', 'url' => route('clubs.index')],
        ['label' => 'Create'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('Create a club') }}</h1>
        <livewire:clubs.create />
    </x-ui.container>
@endsection
