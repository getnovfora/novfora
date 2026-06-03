{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => $topic->title.' · '.config('app.name', 'Hearth')])

@push('head')
    @php($canonical = route('topics.show', $topic))
    <link rel="canonical" href="{{ $canonical }}">
    <meta name="description" content="{{ $description }}">
    <meta property="og:type" content="article">
    <meta property="og:title" content="{{ $topic->title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta name="twitter:card" content="summary">
    {{-- schema.org DiscussionForumPosting (JSON-LD); JSON_HEX_TAG keeps it safe inside <script>. --}}
    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'DiscussionForumPosting',
            'headline' => $topic->title,
            'url' => $canonical,
            'datePublished' => $topic->created_at?->toIso8601String(),
            'dateModified' => $topic->last_posted_at?->toIso8601String(),
            'author' => ['@type' => 'Person', 'name' => $topic->author?->username ?? 'Unknown'],
            'interactionStatistic' => [
                '@type' => 'InteractionCounter',
                'interactionType' => 'https://schema.org/CommentAction',
                'userInteractionCount' => (int) $topic->reply_count,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}
    </script>
@endpush

@section('content')
    <main style="max-width:52rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <nav style="color:#888;font-size:.85rem">
            <a href="{{ route('forums.index') }}">Forums</a> →
            <a href="{{ route('forums.show', $topic->forum) }}">{{ $topic->forum->title }}</a> → {{ $topic->title }}
        </nav>

        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem">
            <h1 style="margin:.3rem 0">
                @if ($topic->is_pinned)📌 @endif
                @if ($topic->status === 'locked')🔒 @endif
                {{ $topic->title }}
            </h1>
            @if ($canModerate)
                <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                    <form method="POST" action="{{ route('topics.pin', $topic) }}">@csrf
                        <button style="padding:.3rem .6rem;border:1px solid #bbb;border-radius:6px;background:#fff;cursor:pointer;font-size:.8rem">{{ $topic->is_pinned ? 'Unpin' : 'Pin' }}</button></form>
                    <form method="POST" action="{{ route('topics.lock', $topic) }}">@csrf
                        <button style="padding:.3rem .6rem;border:1px solid #bbb;border-radius:6px;background:#fff;cursor:pointer;font-size:.8rem">{{ $topic->status === 'locked' ? 'Unlock' : 'Lock' }}</button></form>
                    <form method="POST" action="{{ route('topics.destroy', $topic) }}" onsubmit="return confirm('Move this topic to the recycle bin?')">@csrf @method('DELETE')
                        <button style="padding:.3rem .6rem;border:1px solid #d99;border-radius:6px;background:#fff;color:#a11;cursor:pointer;font-size:.8rem">Delete</button></form>
                </div>
            @endif
        </div>

        @foreach ($posts as $post)
            <article id="post-{{ $post->id }}" style="border:1px solid #ececf1;border-radius:8px;padding:1rem;margin:1rem 0;background:#fff">
                <header style="display:flex;justify-content:space-between;color:#888;font-size:.85rem;margin-bottom:.5rem">
                    <strong style="color:#333">{{ $post->author?->username ?? 'unknown' }}</strong>
                    <span>
                        @if ($post->approved_state === 'pending')<span style="color:#b8860b">⏳ awaiting approval</span> · @endif
                        {{ $post->created_at?->diffForHumans() }}@if ($post->edited_at) · edited @endif
                    </span>
                </header>
                <div class="hearth-prose">{!! $post->body_html_cache !!}</div>
                <footer style="margin-top:.6rem;display:flex;gap:.6rem;font-size:.85rem">
                    @can('update', $post)<a href="{{ route('posts.edit', $post) }}">Edit</a>@endcan
                    @can('delete', $post)
                        <form method="POST" action="{{ route('posts.destroy', $post) }}" style="display:inline" onsubmit="return confirm('Delete this post?')">@csrf @method('DELETE')
                            <button style="border:0;background:none;color:#a11;cursor:pointer;padding:0;font-size:.85rem">Delete</button></form>
                    @endcan
                    @auth
                        <form method="POST" action="{{ route('reports.store') }}" style="display:inline">@csrf
                            <input type="hidden" name="post_id" value="{{ $post->id }}">
                            <button style="border:0;background:none;color:#888;cursor:pointer;padding:0;font-size:.85rem">Report</button></form>
                    @endauth
                </footer>
            </article>
        @endforeach

        <div>{{ $posts->links() }}</div>

        @auth
            @if ($canReply)
                <livewire:forum.reply-composer :topic-id="$topic->id" />
            @elseif ($topic->status === 'locked')
                <p style="color:#888">🔒 This topic is locked.</p>
            @endif
        @else
            <p style="color:#888"><a href="{{ route('login') }}">Sign in</a> to reply.</p>
        @endauth
    </main>
@endsection
