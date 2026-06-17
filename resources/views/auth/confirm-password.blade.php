{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => __('auth.confirm_password.title')])

@section('auth')
    <p class="text-sm text-ink-muted">{{ __('auth.confirm_password.intro') }}</p>

    <form method="POST" action="{{ route('password.confirm') }}" class="mt-4 space-y-4">
        @csrf

        <x-ui.input :label="__('auth.confirm_password.label')" name="password" type="password" required autofocus autocomplete="current-password" />

        <x-ui.button type="submit" size="lg" class="w-full">{{ __('auth.confirm_password.submit') }}</x-ui.button>
    </form>
@endsection
