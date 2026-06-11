{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Notification preferences · '.config('app.name', 'NovFora')])

@section('content')
    <x-settings.shell title="Notifications">
        <p class="text-sm text-ink-muted">
            Choose how you’re notified for each event, and how notification emails are delivered. Email on a
            shared host is best-effort.
        </p>

        <livewire:settings.notification-preferences />
    </x-settings.shell>
@endsection
