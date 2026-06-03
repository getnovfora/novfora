{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Confirm your password'])

@section('auth')
    <p style="color:#555;font-size:.95rem;margin-top:0">This is a secure area. Please confirm your password before continuing.</p>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <label for="password" style="display:block;margin:.6rem 0 .2rem;font-size:.85rem;color:#444">Password</label>
        <input id="password" name="password" type="password" required autofocus autocomplete="current-password"
               style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #bbb;border-radius:6px">

        <button type="submit" style="margin-top:1rem;width:100%;padding:.6rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;font-size:1rem;cursor:pointer">Confirm</button>
    </form>
@endsection
