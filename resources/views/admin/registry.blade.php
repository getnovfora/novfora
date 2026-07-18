{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Registry'])
@section('content')
    <x-admin.shell title="Registry">
        <livewire:admin.registry />
    </x-admin.shell>
@endsection
