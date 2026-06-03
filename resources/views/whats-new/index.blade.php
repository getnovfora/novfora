{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => "What's new · ".config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:46rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <h1>What's new</h1>
        <p style="color:#777">Topics with activity since you last read them.</p>

        @forelse ($topics as $topic)
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid #f0f0f3">
                <a href="{{ route('topics.show', $topic) }}" style="font-weight:600;color:#2d2a6b;text-decoration:none">{{ $topic->title }}</a>
                <span style="color:#999;font-size:.85rem">{{ $topic->forum?->title }} · {{ $topic->last_posted_at?->diffForHumans() }}</span>
            </div>
        @empty
            <p style="color:#999">Nothing new right now — you're all caught up.</p>
        @endforelse
    </main>
@endsection
