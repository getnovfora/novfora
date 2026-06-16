{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => __('auth.password.forgot_title')])

@section('auth')
    <p class="text-sm text-ink-muted">{{ __('auth.password.forgot_intro') }}</p>

    <form method="POST" action="{{ route('password.email') }}" class="mt-4 space-y-4">
        @csrf

        <x-ui.input :label="__('auth.password.email_label')" name="email" type="email" :value="old('email')" required autofocus autocomplete="email" />

        <x-ui.button type="submit" size="lg" class="w-full">{{ __('auth.password.forgot_submit') }}</x-ui.button>
    </form>

    <p class="mt-5 text-sm">
        <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 text-accent hover:underline">
            <x-ui.icon name="arrow-left" class="h-4 w-4" /> {{ __('auth.password.back_to_login') }}
        </a>
    </p>
@endsection
