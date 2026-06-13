{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Modules'])
@section('content')
    <x-admin.shell title="Modules & plugins">
        <livewire:admin.modules />
    </x-admin.shell>
@endsection
