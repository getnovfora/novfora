{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Recycle bin · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:48rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <h1>Recycle bin</h1>
        <p style="color:#777">Soft-deleted content you can restore. Hard purge is a separate, audited maintenance job.</p>

        <h2 style="font-size:1.1rem;margin-top:1.5rem">Deleted topics</h2>
        @forelse ($topics as $topic)
            <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid #f0f0f3">
                <span>{{ $topic->title }} <span style="color:#999">in {{ $topic->forum?->title }}</span></span>
                <form method="POST" action="{{ route('topics.restore', $topic->id) }}">@csrf
                    <button style="padding:.25rem .6rem;border:1px solid #7cc47c;border-radius:6px;background:#fff;color:#1a7a1a;cursor:pointer;font-size:.8rem">Restore</button></form>
            </div>
        @empty
            <p style="color:#999">No deleted topics.</p>
        @endforelse

        <h2 style="font-size:1.1rem;margin-top:1.5rem">Deleted posts</h2>
        @forelse ($posts as $post)
            <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid #f0f0f3">
                <span>Post #{{ $post->id }} <span style="color:#999">in {{ $post->topic?->title }}</span></span>
                <form method="POST" action="{{ route('posts.restore', $post->id) }}">@csrf
                    <button style="padding:.25rem .6rem;border:1px solid #7cc47c;border-radius:6px;background:#fff;color:#1a7a1a;cursor:pointer;font-size:.8rem">Restore</button></form>
            </div>
        @empty
            <p style="color:#999">No deleted posts.</p>
        @endforelse
    </main>
@endsection
