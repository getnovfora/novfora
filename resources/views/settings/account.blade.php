{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Account · '.config('app.name', 'NovFora')])

@section('content')
    <x-settings.shell title="Account">
        <livewire:settings.delete-account />
    </x-settings.shell>
@endsection
