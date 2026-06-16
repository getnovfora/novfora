{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => __('auth.verify_email.title')])

@section('auth')
    <p class="text-sm text-ink-muted">
        {{ __('auth.verify_email.intro') }}
    </p>

    @if (session('status') === 'verification-link-sent')
        <x-ui.alert variant="success" class="mt-4">
            {{ __('auth.verify_email.link_sent') }}
        </x-ui.alert>
    @endif

    <div class="mt-5 flex flex-col gap-3 sm:flex-row">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ui.button type="submit" class="w-full sm:w-auto">{{ __('auth.verify_email.resend_button') }}</x-ui.button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-ui.button type="submit" variant="ghost" class="w-full sm:w-auto">{{ __('auth.verify_email.logout_button') }}</x-ui.button>
        </form>
    </div>
@endsection
