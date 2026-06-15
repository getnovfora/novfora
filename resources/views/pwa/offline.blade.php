{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Offline · '.config('app.name', 'NovFora')])

@section('content')
    <x-ui.container size="sm" class="space-y-5 py-12 text-center">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('You’re offline') }}</h1>
        <p class="text-sm text-ink-subtle">
            {{ __('This page isn’t available offline. Reconnect to the internet and try again — pages you’ve already visited will still load.') }}
        </p>
        <div>
            <x-ui.button :href="route('forums.index')">{{ __('Back to the forums') }}</x-ui.button>
        </div>
    </x-ui.container>
@endsection
