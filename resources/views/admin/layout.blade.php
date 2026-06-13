{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Layout'])
@section('content')
    <x-admin.shell title="Layout & widgets">
        <livewire:admin.layout />
    </x-admin.shell>
@endsection
