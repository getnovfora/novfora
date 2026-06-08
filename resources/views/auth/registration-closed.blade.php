{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Shown when an admin has turned off new registrations (ACP v1, Registration settings). --}}
@extends('layouts.auth', ['authTitle' => 'Registration closed'])

@section('auth')
    <p class="text-sm text-ink-muted">
        New account registration is currently closed. Please check back later.
    </p>
    <div class="mt-5">
        <x-ui.button :href="route('login')">Back to sign in</x-ui.button>
    </div>
@endsection
