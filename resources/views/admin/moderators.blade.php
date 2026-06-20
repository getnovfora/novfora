{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- ACP v3 · v3-b — Moderation → Moderators: the global per-forum moderator overview (assign/remove any forum). --}}
@extends('layouts.app', ['title' => 'Admin · Moderators'])

@section('content')
    <x-admin.shell :title="__('admin.moderators.title')">
        <livewire:admin.moderators />
    </x-admin.shell>
@endsection
