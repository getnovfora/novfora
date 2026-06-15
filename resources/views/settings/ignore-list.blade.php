{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Ignored members · '.config('app.name', 'NovFora')])

@section('content')
    <x-settings.shell title="Ignored members">
        <p class="text-sm text-ink-muted">
            You won’t see posts from members you ignore (their posts collapse, with a “show” link), and they
            can’t send you private messages. Staff actions are never hidden.
        </p>

        <livewire:settings.ignore-list />
    </x-settings.shell>
@endsection
