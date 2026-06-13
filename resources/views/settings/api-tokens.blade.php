{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Settings · API tokens'])
@section('content')
    <x-settings.shell title="API tokens">
        <livewire:settings.api-tokens />
    </x-settings.shell>
@endsection
