{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Choose a new password'])

@section('auth')
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <label for="email" style="display:block;margin:.6rem 0 .2rem;font-size:.85rem;color:#444">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', $request->input('email')) }}" required autofocus autocomplete="email"
               style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">

        <label for="password" style="display:block;margin:.6rem 0 .2rem;font-size:.85rem;color:#444">New password</label>
        <input id="password" name="password" type="password" required autocomplete="new-password"
               style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">

        <label for="password_confirmation" style="display:block;margin:.6rem 0 .2rem;font-size:.85rem;color:#444">Confirm new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
               style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">

        <button type="submit" style="margin-top:1rem;width:100%;padding:.6rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;font-size:1rem;cursor:pointer">Reset password</button>
    </form>
@endsection
