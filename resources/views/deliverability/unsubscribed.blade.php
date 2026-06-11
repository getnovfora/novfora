{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Unsubscribed · '.config('app.name', 'NovFora')])

@section('content')
    <x-ui.container size="md">
        <x-ui.card class="mt-8 text-center">
            <h1 class="text-lg font-semibold text-ink">You're unsubscribed from digests</h1>
            <p class="mt-2 text-sm text-ink-muted">
                {{ $user->email }} will no longer receive digest emails from {{ config('app.name', 'NovFora') }}.
                You can re-enable them any time from your notification preferences.
            </p>
            <div class="mt-5">
                <x-ui.button :href="route('forums.index')" variant="subtle">Back to the forum</x-ui.button>
            </div>
        </x-ui.card>
    </x-ui.container>
@endsection
