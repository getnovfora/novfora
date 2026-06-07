{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Create your account'])

@section('auth')
    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <x-ui.input label="Username" name="username" type="text" :value="old('username')" required autofocus autocomplete="username" />

        <x-ui.input label="Email" name="email" type="email" :value="old('email')" required autocomplete="email" />

        <x-ui.input label="Password" name="password" type="password" required autocomplete="new-password" />

        <x-ui.input label="Confirm password" name="password_confirmation" type="password" required autocomplete="new-password" />

        {{-- Anti-spam (ADR-0007 §2.2). Honeypot: hidden from humans, bots fill it. Timing: encrypted
             render-time, rejected if the form is submitted implausibly fast. --}}
        <div class="absolute -left-[9999px]" aria-hidden="true">
            <label>Leave this field empty
                <input type="text" name="{{ config('hearth.antispam.registration.honeypot.field', 'hp_url') }}"
                       tabindex="-1" autocomplete="off" value="">
            </label>
        </div>
        <input type="hidden" name="hp_ts" value="{{ encrypt((string) now()->timestamp) }}">

        @php($captcha = $captcha ?? [])
        @if (($captcha['type'] ?? null) === 'qa')
            <x-ui.input label="{{ $captcha['question'] }}" :name="$captcha['field']" id="captcha_answer" type="text" required autocomplete="off" />
            @if (! empty($captcha['nonce']))
                {{-- Single-use challenge nonce (phase-1.5 F-B) — replay protection for the Q&A answer. --}}
                <input type="hidden" name="{{ $captcha['nonce_field'] }}" value="{{ $captcha['nonce'] }}">
            @endif
        @elseif (($captcha['type'] ?? null) === 'turnstile')
            <div class="cf-turnstile" data-sitekey="{{ $captcha['site_key'] }}"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
        @endif

        <x-ui.button type="submit" size="lg" class="w-full">Create account</x-ui.button>
    </form>

    <p class="mt-5 text-sm text-ink-muted">
        Already have an account? <a href="{{ route('login') }}" class="text-accent hover:underline">Sign in</a>
    </p>
@endsection
