{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Navigation'])
@section('content')
    <x-admin.shell title="Navigation">
        <livewire:admin.navigation />
    </x-admin.shell>
@endsection
