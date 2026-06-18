{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => __('common.members').' · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => __('common.members')]]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('common.members') }}</h1>
        </div>
        <x-ui.tabs :items="[
            ['label' => __('common.directory'), 'url' => route('members.index'), 'active' => true],
            ['label' => __('common.top_members'), 'url' => route('members.top')],
        ]" />
        {{-- Live "who's online" (Phase 4 · M4.3) — polls on baseline; presence-channel updates on the enhanced tier. --}}
        <livewire:online-members />
        <livewire:members-directory />
    </x-ui.container>
@endsection
