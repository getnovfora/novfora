{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Two-factor authentication'])

@section('auth')
    <p class="text-sm text-ink-muted">
        Enter the 6-digit code from your authenticator app. Lost your device? Use one of your recovery codes instead.
    </p>

    <form method="POST" action="{{ route('two-factor.login') }}" class="mt-4 space-y-4">
        @csrf

        <x-ui.input label="Authentication code" name="code" type="text" inputmode="numeric" autofocus autocomplete="one-time-code" />

        <div class="flex items-center gap-3 text-xs text-ink-subtle">
            <span class="h-px flex-1 bg-line" aria-hidden="true"></span>
            or
            <span class="h-px flex-1 bg-line" aria-hidden="true"></span>
        </div>

        <x-ui.input label="Recovery code" name="recovery_code" type="text" autocomplete="one-time-code" />

        <x-ui.button type="submit" size="lg" class="w-full">Verify</x-ui.button>
    </form>
@endsection
