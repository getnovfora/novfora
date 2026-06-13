{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Top members · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Members', 'url' => route('members.index')],
        ['label' => 'Top members'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Top members</h1>
        </div>
        <x-ui.tabs :items="[
            ['label' => 'Directory', 'url' => route('members.index')],
            ['label' => 'Top members', 'url' => route('members.top'), 'active' => true],
        ]" />
        <livewire:leaderboard />
    </x-ui.container>
@endsection
