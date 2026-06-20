{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- ACP v3 · v3-b — Forums → (forum) → Moderators: per-forum moderator assignment (user/group + preset/custom). --}}
@extends('layouts.app', ['title' => 'Admin · Forum moderators'])

@section('content')
    <x-admin.shell :title="__('admin.moderators.title').' — '.$forum->title">
        <livewire:admin.forum-moderators :forum-id="$forum->id" />
    </x-admin.shell>
@endsection
