{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => $forum->title.' · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:60rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <nav style="color:#888;font-size:.85rem"><a href="{{ route('forums.index') }}">Forums</a> → {{ $forum->title }}</nav>

        <div style="display:flex;justify-content:space-between;align-items:center">
            <h1 style="margin:.3rem 0">{{ $forum->title }}</h1>
            @if ($canPost)
                <a href="{{ route('topics.create', $forum) }}"
                   style="padding:.5rem 1rem;background:#2d2a6b;color:#fff;border-radius:6px;text-decoration:none">New topic</a>
            @endif
        </div>

        @forelse ($topics as $topic)
            <div style="display:flex;justify-content:space-between;gap:1rem;padding:.7rem 0;border-bottom:1px solid #f0f0f3">
                <div>
                    @if ($topic->is_pinned)<span title="Pinned">📌</span>@endif
                    @if ($topic->status === 'locked')<span title="Locked">🔒</span>@endif
                    <a href="{{ route('topics.show', $topic) }}" style="font-weight:600;color:#2d2a6b">{{ $topic->title }}</a>
                    <p style="color:#888;margin:.2rem 0 0;font-size:.85rem">by {{ $topic->author?->username ?? 'unknown' }}</p>
                </div>
                <div style="color:#999;font-size:.85rem;text-align:right;white-space:nowrap">
                    {{ $topic->reply_count }} replies<br>{{ $topic->view_count }} views
                </div>
            </div>
        @empty
            <p style="color:#777">No topics here yet. @if ($canPost)<a href="{{ route('topics.create', $forum) }}">Start one →</a>@endif</p>
        @endforelse

        <div style="margin-top:1rem">{{ $topics->links() }}</div>
    </main>
@endsection
