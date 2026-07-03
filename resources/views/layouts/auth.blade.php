{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => ($authTitle ?? 'Account').' · '.config('app.name', 'NovFora')])

@section('content')
    <x-ui.container size="sm">
        {{-- UX-5 (NOV-94): a focused, centered auth header over the card — brand wordmark + title, calmer
             vertical rhythm. The form inside the card stays left-aligned for scannability. --}}
        <div class="py-8 sm:py-12">
            <div class="mb-6 text-center">
                <p class="text-xs font-semibold uppercase tracking-wider text-ink-subtle">{{ config('app.name', 'NovFora') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink font-display">{{ $authTitle ?? 'Account' }}</h1>
            </div>

            {{-- Session status is shown by the global flash in layouts.app; here we show only validation errors. --}}
            @if ($errors->any())
                <x-ui.alert variant="danger" class="mb-4">
                    <ul class="list-disc pl-4 space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-ui.alert>
            @endif

            <x-ui.card>
                @yield('auth')
            </x-ui.card>
        </div>
    </x-ui.container>
@endsection
