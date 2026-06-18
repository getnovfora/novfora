{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Primary group · '.config('app.name', 'NovFora')])

@section('content')
    <x-settings.shell title="Primary group">
        <p class="text-sm text-ink-muted">
            Choose which group to display as your rank badge and name colour.
        </p>

        <livewire:settings.primary-group />
    </x-settings.shell>
@endsection
