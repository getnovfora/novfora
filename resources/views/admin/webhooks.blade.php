{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Webhooks'])
@section('content')
    <x-admin.shell title="Webhooks">
        <livewire:admin.webhooks />
    </x-admin.shell>
@endsection
