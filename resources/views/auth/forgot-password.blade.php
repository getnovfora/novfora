{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Reset your password'])

@section('auth')
    <p style="color:#555;font-size:.95rem;margin-top:0">Enter your email and we’ll send you a password-reset link.</p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <label for="email" style="display:block;margin:.6rem 0 .2rem;font-size:.85rem;color:#444">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email"
               style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">

        <button type="submit" style="margin-top:1rem;width:100%;padding:.6rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;font-size:1rem;cursor:pointer">Email reset link</button>
    </form>

    <p style="margin-top:1rem;font-size:.9rem;color:#555"><a href="{{ route('login') }}">← Back to sign in</a></p>
@endsection
