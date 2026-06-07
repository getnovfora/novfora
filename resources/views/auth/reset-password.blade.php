{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => 'Choose a new password'])

@section('auth')
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-ui.input label="Email" name="email" type="email" :value="old('email', $request->input('email'))" required autofocus autocomplete="email" />

        <x-ui.input label="New password" name="password" type="password" required autocomplete="new-password" />

        <x-ui.input label="Confirm new password" name="password_confirmation" type="password" required autocomplete="new-password" />

        <x-ui.button type="submit" size="lg" class="w-full">Reset password</x-ui.button>
    </form>
@endsection
