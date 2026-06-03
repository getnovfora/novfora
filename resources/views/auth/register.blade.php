{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Create your account'])

@section('auth')
    <form method="POST" action="{{ route('register') }}">
        @csrf

        <label for="username" style="display:block;margin:.6rem 0 .2rem;font-size:.85rem;color:#444">Username</label>
        <input id="username" name="username" type="text" value="{{ old('username') }}" required autofocus autocomplete="username"
               style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">

        <label for="email" style="display:block;margin:.6rem 0 .2rem;font-size:.85rem;color:#444">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
               style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">

        <label for="password" style="display:block;margin:.6rem 0 .2rem;font-size:.85rem;color:#444">Password</label>
        <input id="password" name="password" type="password" required autocomplete="new-password"
               style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">

        <label for="password_confirmation" style="display:block;margin:.6rem 0 .2rem;font-size:.85rem;color:#444">Confirm password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
               style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">

        {{-- Anti-spam (ADR-0007 §2.2). Honeypot: hidden from humans, bots fill it. Timing: encrypted
             render-time, rejected if the form is submitted implausibly fast. --}}
        <div style="position:absolute;left:-9999px" aria-hidden="true">
            <label>Leave this field empty
                <input type="text" name="{{ config('hearth.antispam.registration.honeypot.field', 'hp_url') }}"
                       tabindex="-1" autocomplete="off" value="">
            </label>
        </div>
        <input type="hidden" name="hp_ts" value="{{ encrypt((string) now()->timestamp) }}">

        @php($captcha = $captcha ?? [])
        @if (($captcha['type'] ?? null) === 'qa')
            <label for="captcha_answer" style="display:block;margin:.6rem 0 .2rem;font-size:.85rem;color:#444">{{ $captcha['question'] }}</label>
            <input id="captcha_answer" name="{{ $captcha['field'] }}" type="text" required autocomplete="off"
                   style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">
        @elseif (($captcha['type'] ?? null) === 'turnstile')
            <div class="cf-turnstile" data-sitekey="{{ $captcha['site_key'] }}" style="margin-top:.6rem"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif

        <button type="submit" style="margin-top:1rem;width:100%;padding:.6rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;font-size:1rem;cursor:pointer">Create account</button>
    </form>

    <p style="margin-top:1rem;font-size:.9rem;color:#555">
        Already have an account? <a href="{{ route('login') }}">Sign in</a>
    </p>
@endsection
