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
    {{-- schema.org DiscussionForumPosting (JSON-LD); JSON_HEX_TAG keeps it safe inside <script>.
         nonce carries the strict-CSP token when enabled (phase-1.5 F-M3); empty/ignored under the baseline. --}}
    <script type="application/ld+json" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
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

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => $topic->forum->title, 'url' => route('forums.show', $topic->forum)],
        ['label' => $topic->title],
    ]" />
@endsection

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 space-y-2">
                @if ($topic->is_pinned || $topic->status === 'locked')
                    <div class="flex flex-wrap items-center gap-1.5">
                        @if ($topic->is_pinned)
                            <x-ui.badge variant="accent"><x-ui.icon name="pin" class="h-3.5 w-3.5" /> Pinned</x-ui.badge>
                        @endif
                        @if ($topic->status === 'locked')
                            <x-ui.badge variant="warn"><x-ui.icon name="lock" class="h-3.5 w-3.5" /> Locked</x-ui.badge>
                        @endif
                    </div>
                @endif
                <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $topic->title }}</h1>
            </div>

            @if ($canModerate)
                <div class="flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ route('topics.pin', $topic) }}">@csrf
                        <x-ui.button type="submit" variant="ghost" size="sm">
                            <x-ui.icon name="pin" class="h-4 w-4" /> {{ $topic->is_pinned ? 'Unpin' : 'Pin' }}
                        </x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('topics.lock', $topic) }}">@csrf
                        <x-ui.button type="submit" variant="ghost" size="sm">
                            <x-ui.icon name="lock" class="h-4 w-4" /> {{ $topic->status === 'locked' ? 'Unlock' : 'Lock' }}
                        </x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('topics.destroy', $topic) }}" onsubmit="return confirm('Move this topic to the recycle bin?')">@csrf @method('DELETE')
                        <x-ui.button type="submit" variant="danger-ghost" size="sm">Delete</x-ui.button>
                    </form>
                </div>
            @endif
        </div>

        <div class="space-y-4">
            @foreach ($posts as $post)
                <x-ui.card id="post-{{ $post->id }}" :class="$post->approved_state === 'pending' ? 'ring-1 ring-warn-soft' : ''">
                    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line pb-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-ui.avatar :user="$post->author" size="md" />
                            <div class="min-w-0">
                                <p class="font-semibold text-ink truncate">{{ $post->author?->username ?? 'unknown' }}</p>
                                <p class="text-xs text-ink-subtle nums">
                                    {{ $post->created_at?->diffForHumans() }}@if ($post->edited_at) · edited @endif
                                </p>
                            </div>
                        </div>
                        @if ($post->approved_state === 'pending')
                            <x-ui.badge variant="warn">awaiting approval</x-ui.badge>
                        @endif
                    </header>

                    <div class="hearth-prose pt-4">{!! $post->body_html_cache !!}</div>

                    <footer class="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-3">
                        @can('update', $post)
                            <x-ui.button :href="route('posts.edit', $post)" variant="subtle" size="sm">Edit</x-ui.button>
                        @endcan
                        @can('delete', $post)
                            <form method="POST" action="{{ route('posts.destroy', $post) }}" onsubmit="return confirm('Delete this post?')">@csrf @method('DELETE')
                                <x-ui.button type="submit" variant="danger-ghost" size="sm">Delete</x-ui.button>
                            </form>
                        @endcan
                        @auth
                            <form method="POST" action="{{ route('reports.store') }}" class="ml-auto">@csrf
                                <input type="hidden" name="post_id" value="{{ $post->id }}">
                                <x-ui.button type="submit" variant="ghost" size="sm">
                                    <x-ui.icon name="flag" class="h-4 w-4" /> Report
                                </x-ui.button>
                            </form>
                        @endauth
                    </footer>
                </x-ui.card>
            @endforeach
        </div>

        <div>{{ $posts->links() }}</div>

        @auth
            @if ($canReply)
                <livewire:forum.reply-composer :topic-id="$topic->id" />
            @elseif ($topic->status === 'locked')
                <x-ui.card class="flex items-center gap-3 text-ink-muted">
                    <x-ui.icon name="lock" class="h-5 w-5 shrink-0 text-ink-subtle" />
                    <p class="text-sm">This topic is locked — no new replies can be posted.</p>
                </x-ui.card>
            @endif
        @else
            <x-ui.card class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-ink-muted">Join the conversation to leave a reply.</p>
                <x-ui.button :href="route('login')" size="sm">Sign in to reply</x-ui.button>
            </x-ui.card>
        @endauth
    </x-ui.container>
@endsection
