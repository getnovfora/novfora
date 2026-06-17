{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.auth', ['authTitle' => __('auth.reset.title')])

@section('auth')
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-ui.input :label="__('auth.reset.email_label')" name="email" type="email" :value="old('email', $request->input('email'))" required autofocus autocomplete="email" />

        <x-ui.input :label="__('auth.reset.new_password_label')" name="password" type="password" required autocomplete="new-password" />

        <x-ui.input :label="__('auth.reset.confirm_password_label')" name="password_confirmation" type="password" required autocomplete="new-password" />

        <x-ui.button type="submit" size="lg" class="w-full">{{ __('auth.reset.submit') }}</x-ui.button>
    </form>
@endsection
