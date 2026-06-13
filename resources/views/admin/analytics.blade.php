{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Analytics'])
@section('content')
    <x-admin.shell title="Analytics">
        <livewire:admin.analytics />
    </x-admin.shell>
@endsection
