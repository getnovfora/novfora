{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Moderation queue · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:48rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <h1>Moderation queue</h1>
        <p style="color:#777">Content held by the anti-spam layer — new-user posts, flagged words, and suspicious content awaiting review.</p>

        <h2 style="font-size:1.1rem;margin-top:1.5rem">Pending topics</h2>
        @forelse ($topics as $topic)
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid #f0f0f3">
                <span>{{ $topic->title }} <span style="color:#999">by {{ $topic->author?->username }} in {{ $topic->forum?->title }}</span></span>
                <span style="display:flex;gap:.4rem">
                    <form method="POST" action="{{ route('topics.approve', $topic->id) }}">@csrf
                        <button style="padding:.25rem .6rem;border:1px solid #7cc47c;border-radius:6px;background:#fff;color:#1a7a1a;cursor:pointer;font-size:.8rem">Approve</button></form>
                    <form method="POST" action="{{ route('topics.reject', $topic->id) }}">@csrf
                        <button style="padding:.25rem .6rem;border:1px solid #d99;border-radius:6px;background:#fff;color:#b00020;cursor:pointer;font-size:.8rem">Reject</button></form>
                </span>
            </div>
        @empty
            <p style="color:#999">No topics awaiting review.</p>
        @endforelse

        <h2 style="font-size:1.1rem;margin-top:1.5rem">Pending posts</h2>
        @forelse ($posts as $post)
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid #f0f0f3">
                <span>Reply by {{ $post->author?->username }} <span style="color:#999">in {{ $post->topic?->title }}</span></span>
                <span style="display:flex;gap:.4rem">
                    <form method="POST" action="{{ route('posts.approve', $post->id) }}">@csrf
                        <button style="padding:.25rem .6rem;border:1px solid #7cc47c;border-radius:6px;background:#fff;color:#1a7a1a;cursor:pointer;font-size:.8rem">Approve</button></form>
                    <form method="POST" action="{{ route('posts.reject', $post->id) }}">@csrf
                        <button style="padding:.25rem .6rem;border:1px solid #d99;border-radius:6px;background:#fff;color:#b00020;cursor:pointer;font-size:.8rem">Reject</button></form>
                </span>
            </div>
        @empty
            <p style="color:#999">No posts awaiting review.</p>
        @endforelse
    </main>
@endsection
