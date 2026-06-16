{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => __('auth.two_factor.title')])

@section('auth')
    <p class="text-sm text-ink-muted">
        {{ __('auth.two_factor.intro') }}
    </p>

    <form method="POST" action="{{ route('two-factor.login') }}" class="mt-4 space-y-4">
        @csrf

        <x-ui.input :label="__('auth.two_factor.code_label')" name="code" type="text" inputmode="numeric" autofocus autocomplete="one-time-code" />

        <div class="flex items-center gap-3 text-xs text-ink-subtle">
            <span class="h-px flex-1 bg-line" aria-hidden="true"></span>
            {{ __('auth.two_factor.or') }}
            <span class="h-px flex-1 bg-line" aria-hidden="true"></span>
        </div>

        <x-ui.input :label="__('auth.two_factor.recovery_code_label')" name="recovery_code" type="text" autocomplete="one-time-code" />

        <x-ui.button type="submit" size="lg" class="w-full">{{ __('auth.two_factor.submit') }}</x-ui.button>
    </form>
@endsection
