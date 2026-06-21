{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => __('forum.staff_page_title').' · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => __('forum.staff_page_title')]]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('forum.staff_page_title') }}</h1>
            <p class="mt-1 text-sm text-ink-muted">{{ __('forum.staff_page_intro') }}</p>
        </div>
        <livewire:community.staff-roster />
    </x-ui.container>
@endsection
