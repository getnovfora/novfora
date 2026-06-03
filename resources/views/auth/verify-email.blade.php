{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Verify your email'])

@section('auth')
    <p style="color:#555;font-size:.95rem;margin-top:0">
        Thanks for registering! Please click the link in the email we just sent to finish setting up your
        account. If you didn’t receive it, request another below.
    </p>

    @if (session('status') === 'verification-link-sent')
        <p style="background:#eef7ee;border:1px solid #bcdcbc;color:#1a6b1a;padding:.55rem .8rem;border-radius:6px">
            A fresh verification link has been sent to your email address.
        </p>
    @endif

    <div style="display:flex;gap:.6rem;margin-top:.5rem">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" style="padding:.55rem .9rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;cursor:pointer">Resend verification email</button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" style="padding:.55rem .9rem;border:1px solid #bbb;border-radius:6px;background:#fff;cursor:pointer">Log out</button>
        </form>
    </div>
@endsection
