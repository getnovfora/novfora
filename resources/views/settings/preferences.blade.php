{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Preferences · '.config('app.name', 'NovFora')])

@section('content')
    <x-settings.shell title="Preferences">
        <p class="text-sm text-ink-muted">
            How threads read for you. These apply only to your account.
        </p>

        <livewire:settings.user-preferences />
    </x-settings.shell>
@endsection
