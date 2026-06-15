{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Sign in'])

@section('auth')
    @if (session('error'))
        <x-ui.alert variant="danger" class="mb-4">{{ session('error') }}</x-ui.alert>
    @endif

    @php($socialProviders = app(\App\Auth\Social\SocialProviders::class)->available())
    @if (! empty($socialProviders))
        <div class="mb-5 space-y-2" aria-label="{{ __('Sign in with a provider') }}">
            @foreach ($socialProviders as $provider)
                <a href="{{ route('oauth.redirect', $provider) }}"
                   class="flex w-full items-center justify-center gap-2 min-h-11 rounded-md border border-line bg-surface-raised px-4 text-sm font-medium text-ink hover:bg-surface-sunken"
                   data-provider="{{ $provider }}">
                    {{ __('Continue with :provider', ['provider' => app(\App\Auth\Social\SocialProviders::class)->label($provider)]) }}
                </a>
            @endforeach
        </div>
        <div class="relative mb-5 text-center text-xs text-ink-subtle">
            <span class="bg-surface px-2">{{ __('or sign in with your password') }}</span>
            <span class="absolute inset-x-0 top-1/2 -z-10 border-t border-line" aria-hidden="true"></span>
        </div>
    @endif

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
