{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => __('auth.register.title')])

@section('auth')
    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <x-ui.input :label="__('auth.register.username_label')" name="username" type="text" :value="old('username')" required autofocus autocomplete="username" />

        <x-ui.input :label="__('auth.register.email_label')" name="email" type="email" :value="old('email')" required autocomplete="email" />

        <x-ui.input :label="__('auth.register.password_label')" name="password" type="password" required autocomplete="new-password" />

        <x-ui.input :label="__('auth.register.password_confirm_label')" name="password_confirmation" type="password" required autocomplete="new-password" />

        {{-- Anti-spam (ADR-0007 §2.2). Honeypot: hidden from humans, bots fill it. Timing: encrypted
             render-time, rejected if the form is submitted implausibly fast. --}}
        <div class="absolute -left-[9999px]" aria-hidden="true">
            <label>{{ __('auth.register.honeypot_label') }}
                <input type="text" name="{{ config('novfora.antispam.registration.honeypot.field', 'hp_url') }}"
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

        <x-ui.button type="submit" size="lg" class="w-full">{{ __('auth.register.submit') }}</x-ui.button>
    </form>

    <p class="mt-5 text-sm text-ink-muted">
        {{ __('auth.register.already_have_account') }} <a href="{{ route('login') }}" class="text-accent hover:underline">{{ __('auth.register.sign_in_link') }}</a>
    </p>
@endsection
