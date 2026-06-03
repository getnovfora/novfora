{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => ($q !== '' ? "Search: {$q}" : 'Search').' · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:46rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <h1>Search</h1>

        <form method="GET" action="{{ route('search.index') }}" role="search" style="display:flex;gap:.5rem;margin:1rem 0">
            <label for="q" class="sr-only">Search posts</label>
            <input id="q" type="search" name="q" value="{{ $q }}" placeholder="Search posts…" autofocus
                   style="flex:1;padding:.55rem;border:1px solid #bbb;border-radius:6px">
            <button type="submit" style="padding:.55rem 1.1rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;cursor:pointer">Search</button>
        </form>

        @if ($q !== '')
            <p style="color:#777">{{ $results->count() }} {{ \Illuminate\Support\Str::plural('result', $results->count()) }} for “{{ $q }}”</p>

            @forelse ($results as $post)
                <article style="padding:.7rem 0;border-bottom:1px solid #f0f0f3">
                    <a href="{{ route('topics.show', $post->topic_id) }}#post-{{ $post->id }}" style="font-weight:600;color:#2d2a6b;text-decoration:none">
                        {{ $post->topic?->title ?? 'Topic' }}
                    </a>
                    <p style="margin:.3rem 0 0;color:#555">{{ \Illuminate\Support\Str::limit($post->body_text, 180) }}</p>
                </article>
            @empty
                <p style="color:#999">No posts matched your search.</p>
            @endforelse
        @endif
    </main>
@endsection
