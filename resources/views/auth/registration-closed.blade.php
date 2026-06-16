{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Shown when an admin has turned off new registrations (ACP v1, Registration settings). --}}
@extends('layouts.auth', ['authTitle' => __('auth.registration_closed.title')])

@section('auth')
    <p class="text-sm text-ink-muted">
        {{ __('auth.registration_closed.message') }}
    </p>
    <div class="mt-5">
        <x-ui.button :href="route('login')">{{ __('auth.registration_closed.back_button') }}</x-ui.button>
    </div>
@endsection
