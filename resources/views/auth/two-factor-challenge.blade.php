{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Two-factor authentication'])

@section('auth')
    <p style="color:#555;font-size:.95rem;margin-top:0">
        Enter the 6-digit code from your authenticator app. Lost your device? Use one of your recovery codes instead.
    </p>

    <form method="POST" action="{{ route('two-factor.login') }}">
        @csrf

        <label for="code" style="display:block;margin:.6rem 0 .2rem;font-size:.85rem;color:#444">Authentication code</label>
        <input id="code" name="code" type="text" inputmode="numeric" autofocus autocomplete="one-time-code"
               style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">

        <label for="recovery_code" style="display:block;margin:.9rem 0 .2rem;font-size:.85rem;color:#444">…or a recovery code</label>
        <input id="recovery_code" name="recovery_code" type="text" autocomplete="one-time-code"
               style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">

        <button type="submit" style="margin-top:1rem;width:100%;padding:.6rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;font-size:1rem;cursor:pointer">Verify</button>
    </form>
@endsection
