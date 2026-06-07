{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Sign in'])

@section('auth')
    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <x-ui.input label="Email" name="email" type="email" :value="old('email')" required autofocus autocomplete="username" />

        <x-ui.input label="Password" name="password" type="password" required autocomplete="current-password" />

        <label class="flex items-center gap-2.5 text-sm text-ink">
            <input type="checkbox" name="remember"
                   class="h-4 w-4 rounded-sm border-line text-accent focus:ring-accent">
            Remember me
        </label>

        <x-ui.button type="submit" size="lg" class="w-full">Sign in</x-ui.button>
    </form>

    <p class="mt-5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-muted">
        <a href="{{ route('password.request') }}" class="text-accent hover:underline">Forgot your password?</a>
        @if (Route::has('register'))
            <span class="text-ink-subtle" aria-hidden="true">&middot;</span>
            <a href="{{ route('register') }}" class="text-accent hover:underline">Create an account</a>
        @endif
    </p>
@endsection
