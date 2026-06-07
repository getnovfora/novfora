{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Verify your email'])

@section('auth')
    <p class="text-sm text-ink-muted">
        Thanks for registering! Please click the link in the email we just sent to finish setting up your
        account. If you didn’t receive it, request another below.
    </p>

    @if (session('status') === 'verification-link-sent')
        <x-ui.alert variant="success" class="mt-4">
            A fresh verification link has been sent to your email address.
        </x-ui.alert>
    @endif

    <div class="mt-5 flex flex-col gap-3 sm:flex-row">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ui.button type="submit" class="w-full sm:w-auto">Resend verification email</x-ui.button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-ui.button type="submit" variant="ghost" class="w-full sm:w-auto">Log out</x-ui.button>
        </form>
    </div>
@endsection
