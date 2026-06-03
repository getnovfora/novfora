{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'New topic · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:52rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <nav style="color:#888;font-size:.85rem">
            <a href="{{ route('forums.index') }}">Forums</a> →
            <a href="{{ route('forums.show', $forum) }}">{{ $forum->title }}</a> → New topic
        </nav>
        <h1>New topic in {{ $forum->title }}</h1>

        <livewire:forum.create-topic :forum-id="$forum->id" />
    </main>
@endsection
