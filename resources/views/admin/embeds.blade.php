{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Admin · Embeds'])
@section('content')
    <x-admin.shell title="Embed widgets">
        <livewire:admin.embeds />
    </x-admin.shell>
@endsection
