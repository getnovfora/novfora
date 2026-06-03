{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Sign in'])

@section('auth')
    <form method="POST" action="{{ route('login') }}">
        @csrf

        <label for="email" style="display:block;margin:.6rem 0 .2rem;font-size:.85rem;color:#444">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
               style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">

        <label for="password" style="display:block;margin:.6rem 0 .2rem;font-size:.85rem;color:#444">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password"
               style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">

        <label style="display:flex;align-items:center;gap:.4rem;margin-top:.7rem;font-size:.9rem;color:#444">
            <input type="checkbox" name="remember"> Remember me
        </label>

        <button type="submit" style="margin-top:1rem;width:100%;padding:.6rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;font-size:1rem;cursor:pointer">Sign in</button>
    </form>

    <p style="margin-top:1rem;font-size:.9rem;color:#555">
        <a href="{{ route('password.request') }}">Forgot your password?</a>
        @if (Route::has('register')) &middot; <a href="{{ route('register') }}">Create an account</a> @endif
    </p>
@endsection
