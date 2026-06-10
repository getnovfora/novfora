{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Spike P2 / P2-M2 — the GET landing page for the signed 1-click unsubscribe link. It DOES NOT apply the
     opt-out: only the POST does (RFC 8058 one-click, or this form). A GET resists email-scanner prefetch — a
     scanner that fetches the link can't silently unsubscribe the user. $action is the same signed URL. --}}
@extends('layouts.app', ['title' => 'Unsubscribe · '.config('app.name', 'Hearth')])

@section('content')
    <x-ui.container size="md">
        <x-ui.card class="mt-8 text-center">
            <h1 class="text-lg font-semibold text-ink">Unsubscribe from digests?</h1>
            <p class="mt-2 text-sm text-ink-muted">
                Confirm to stop digest emails to {{ $user->email }} from {{ config('app.name', 'Hearth') }}.
                You can re-enable them any time from your notification preferences.
            </p>
            <form method="POST" action="{{ $action }}" class="mt-5 flex flex-wrap items-center justify-center gap-3">
                {{-- CSRF-exempt (bootstrap/app.php): the signed-URL HMAC is the authentication. --}}
                <x-ui.button type="submit">Yes, unsubscribe</x-ui.button>
                <x-ui.button :href="route('forums.index')" variant="subtle">No, keep them</x-ui.button>
            </form>
        </x-ui.card>
    </x-ui.container>
@endsection
