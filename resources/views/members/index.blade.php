{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Members · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Members']]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Members</h1>
        </div>
        <x-ui.tabs :items="[
            ['label' => 'Directory', 'url' => route('members.index'), 'active' => true],
            ['label' => 'Top members', 'url' => route('members.top')],
        ]" />
        <livewire:members-directory />
    </x-ui.container>
@endsection
