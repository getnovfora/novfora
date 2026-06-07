{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Confirm your password'])

@section('auth')
    <p class="text-sm text-ink-muted">This is a secure area. Please confirm your password before continuing.</p>

    <form method="POST" action="{{ route('password.confirm') }}" class="mt-4 space-y-4">
        @csrf

        <x-ui.input label="Password" name="password" type="password" required autofocus autocomplete="current-password" />

        <x-ui.button type="submit" size="lg" class="w-full">Confirm</x-ui.button>
    </form>
@endsection
