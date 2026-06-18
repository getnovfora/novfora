{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Groups · Join requests'])
@section('content')
    <x-admin.shell title="Group join requests">
        <livewire:admin.group-requests />
    </x-admin.shell>
@endsection
