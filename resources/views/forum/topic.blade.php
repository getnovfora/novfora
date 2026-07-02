{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => $topic->title.' · '.config('app.name', 'NovFora')])

@push('head')
    @php($canonical = route('topics.show', $topic))
    <link rel="canonical" href="{{ $canonical }}">
    <meta name="description" content="{{ $description }}">
    <meta property="og:type" content="article">
    <meta property="og:title" content="{{ $topic->title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta name="twitter:card" content="summary">
    {{-- RSS/Atom auto-discovery (discovery 3.2). --}}
    <link rel="alternate" type="application/atom+xml" title="{{ $topic->title }} — feed" href="{{ route('feeds.topic', $topic) }}">
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
        ['label' => __('common.forums'), 'url' => route('forums.index')],
        ['label' => $topic->forum->title, 'url' => route('forums.show', $topic->forum)],
        ['label' => $topic->title],
    ]" />
@endsection

@section('content')
    {{-- size="lg" follows the site Appearance "Forum width" (--layout-max-width) — the same shared
         container the forum index and board views use, so the width setting governs the topic view too. --}}
    {{-- x-data scopes the page so Alpine initialises the bulk-select toggle + per-post checkboxes (P2-M4),
         which read the global $store.bulkSelect; nested Livewire components keep their own Alpine scopes. --}}
    <x-ui.container size="lg" class="space-y-5" x-data="{}"
        x-bind:style="$store.bulkSelect.active ? 'padding-bottom: 7rem' : ''">
        {{-- Theme Studio 1.3: configurable region — admin-placed widgets at the top of a topic. --}}
        <x-region name="topic_top" />
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 space-y-2">
                @if ($topic->is_pinned || $topic->status === 'locked' || $topic->prefix || $topic->tags->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-1.5">
                        <x-forum.prefix-badge :prefix="$topic->prefix" />
                        @foreach ($topic->tags as $tag)
                            <x-forum.tag-chip :tag="$tag" />
                        @endforeach
                        @if ($topic->is_pinned)
                            <x-ui.badge variant="accent"><x-ui.icon name="pin" class="h-3.5 w-3.5" /> {{ __('forum.pinned') }}</x-ui.badge>
                        @endif
                        @if ($topic->status === 'locked')
                            <x-ui.badge variant="warn"><x-ui.icon name="lock" class="h-3.5 w-3.5" /> {{ __('forum.locked') }}</x-ui.badge>
                        @endif
                    </div>
                @endif
                <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $topic->title }}</h1>
            </div>

            <div class="flex items-center gap-2">
                @auth
                    {{-- M2: follow this topic → notified of new replies. --}}
                    <livewire:forum.subscribe-button :key="'sub-topic-'.$topic->id" kind="topic" :target-id="$topic->id" />
                @endauth
                @if ($canBookmark)
                    {{-- Member tool 2.1: save this topic. --}}
                    <livewire:forum.bookmark-button :key="'bm-topic-'.$topic->id" kind="topic" :target-id="$topic->id"
                        :saved="$topicBookmarked" :can-save="$canBookmark" />
                @endif
            </div>

            @if ($canModerate)
                <div class="flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ route('topics.pin', $topic) }}">@csrf
                        <x-ui.button type="submit" variant="ghost" size="sm">
                            <x-ui.icon name="pin" class="h-4 w-4" /> {{ $topic->is_pinned ? __('forum.unpin') : __('forum.pin') }}
                        </x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('topics.lock', $topic) }}">@csrf
                        <x-ui.button type="submit" variant="ghost" size="sm">
                            <x-ui.icon name="lock" class="h-4 w-4" /> {{ $topic->status === 'locked' ? __('forum.unlock') : __('forum.lock') }}
                        </x-ui.button>
                    </form>
                    {{-- Merge this topic into another (P2-M4): trigger + modal SFC. Hidden (not disabled)
                         when the rank guard would refuse the source author — rank leakage has no
                         user-actionable remedy, so a control that can only fail is pure ghost UI (NOV-88). --}}
                    @if ($canMergeTopic)
                        <livewire:forum.merge-topic :topic-id="$topic->id" />
                    @endif
                    {{-- Bulk-select toggle (P2-M4): turns on per-post checkboxes wired to the Alpine bulkSelect store. --}}
                    <x-ui.button type="button" variant="ghost" size="sm" dusk="bulk-select-toggle"
                                 x-on:click="$store.bulkSelect.toggleMode()"
                                 x-bind:class="$store.bulkSelect.active ? 'border-accent text-accent' : ''">
                        <span x-text="$store.bulkSelect.active ? @js(__('forum.done_selecting')) : @js(__('forum.select_posts'))"></span>
                    </x-ui.button>
                    <form method="POST" action="{{ route('topics.destroy', $topic) }}" onsubmit="return confirm('{{ __('forum.confirm_trash_topic') }}')">@csrf @method('DELETE')
                        <x-ui.button type="submit" variant="danger-ghost" size="sm">{{ __('common.delete') }}</x-ui.button>
                    </form>
                </div>
            @endif
        </div>

        {{-- Poster-info position is a site Appearance setting (ACP v1): left (default) / right / top.
             Presentation only — the avatar/name/badge/stats markup and #post-* ids are unchanged. --}}
        @php($posterPosition = (app(\App\Settings\Settings::class)->siteView()['poster_position'] ?? null) ?: 'left')
        @php($postWrap = match ($posterPosition) {
            'top' => '',
            'right' => 'md:flex md:flex-row-reverse md:gap-5',
            default => 'md:flex md:gap-5',
        })
        @php($posterCol = match ($posterPosition) {
            'top' => 'flex items-center gap-3 border-b border-line pb-3',
            'right' => 'flex items-center gap-3 border-b border-line pb-3 md:w-40 md:shrink-0 md:flex-col md:items-center md:gap-1.5 md:border-b-0 md:border-l md:pb-0 md:pl-5 md:text-center',
            default => 'flex items-center gap-3 border-b border-line pb-3 md:w-40 md:shrink-0 md:flex-col md:items-center md:gap-1.5 md:border-b-0 md:border-r md:pb-0 md:pr-5 md:text-center',
        })
        @php($bodyCol = $posterPosition === 'top' ? 'min-w-0 pt-3' : 'min-w-0 flex-1 pt-3 md:pt-0')

        @if ($pollData)
            <livewire:forum.poll :poll-id="$topic->poll_id" :topic-id="$topic->id"
                :poll="$pollData" :voted="$pollVotes" :can-vote="$canVote" />
        @endif

        <div class="space-y-4">
            @foreach ($posts as $post)
                @php($author = $post->author)
                @php($staffRole = $author?->staffRole())
                {{-- Member tool 2.2: collapse a post by an ignored member — but NEVER a staff member (staffRole set). --}}
                @php($authorIgnored = $staffRole === null && in_array((int) $post->user_id, $ignoredIds ?? [], true))
                <x-ui.card id="post-{{ $post->id }}" :class="$post->approved_state === 'pending' ? 'ring-1 ring-warn-soft' : ''">
                    @if ($canModerate)
                        {{-- Bulk-select checkbox (P2-M4): shown only in select mode, bound to the Alpine store. --}}
                        <label x-show="$store.bulkSelect.active" x-cloak class="mb-2 inline-flex items-center gap-2 text-xs text-ink-muted">
                            <input type="checkbox" :checked="$store.bulkSelect.has({{ $post->id }})"
                                   x-on:change="$store.bulkSelect.toggle({{ $post->id }})"
                                   dusk="bulk-post-{{ $post->id }}"
                                   class="h-4 w-4 rounded-sm border-line-strong text-accent focus-visible:ring-accent">
                            {{ __('forum.select_this_post') }}
                        </label>
                    @endif
                    <div class="{{ $postWrap }}">
                        {{-- Poster --}}
                        <div class="{{ $posterCol }}">
                            <span class="md:hidden"><x-ui.avatar :user="$author" :guest="$author === null" size="md" /></span>
                            <span class="hidden md:inline-flex"><x-ui.avatar :user="$author" :guest="$author === null" size="xl" /></span>
                            <div class="min-w-0 md:mt-1">
                                <p class="font-semibold text-ink truncate"><x-ui.user-name :user="$author" :link="true" :fallback="__('[Deleted]')" /></p>
                                {{-- Live staff flair (ACP v3 · v3-g) — replaces the old inline role badge; gated by members.staff_flair_show_badge. --}}
                                <x-ui.staff-flair :user="$author" class="mt-1" />
                                {{-- Poster stats from already-loaded columns (desktop sidebar only). --}}
                                <dl class="mt-2 hidden space-y-0.5 text-xs text-ink-subtle md:block">
                                    @if ($author?->created_at)
                                        <div><dt class="sr-only">{{ __('forum.joined_label') }}</dt><dd>{{ __('forum.joined_on', ['date' => $author->created_at->isoFormat('MMM YYYY')]) }}</dd></div>
                                    @endif
                                    <div><dt class="sr-only">{{ __('forum.posts_label') }}</dt><dd class="nums">{{ __('forum.post_count', ['count' => number_format((int) ($author?->post_count ?? 0))]) }}</dd></div>
                                </dl>
                            </div>
                        </div>

                        {{-- Body --}}
                        <div class="min-w-0 flex-1 pt-3 md:pt-0">
                            <div class="flex flex-wrap items-center gap-2 text-xs text-ink-subtle nums md:border-b md:border-line md:pb-2">
                                <span>{{ $post->created_at?->diffForHumans() }}@if ($post->edited_at) · {{ __('forum.edited') }} @endif</span>
                                @if ($post->approved_state === 'pending')
                                    <x-ui.badge variant="warn" class="ml-auto">{{ __('forum.awaiting_approval') }}</x-ui.badge>
                                @endif
                            </div>

                            @if ($authorIgnored)
                                <div class="pt-3 md:pt-4" x-data="{ show: false }" dusk="ignored-post-{{ $post->id }}">
                                    <button type="button" x-show="!show" @click="show = true"
                                            class="w-full rounded-md border border-dashed border-line px-3 py-2 text-left text-xs text-ink-muted hover:border-accent">
                                        {{ __('You ignore this member.') }} <span class="text-accent">{{ __('Show this post') }}</span>
                                    </button>
                                    <div x-show="show" x-cloak class="novfora-prose">{!! $post->body_html_cache !!}</div>
                                </div>
                            @else
                                <div class="novfora-prose pt-3 md:pt-4">{!! $post->body_html_cache !!}</div>
                            @endif

                            {{-- Per-post extension outlet (ADR-0031/0032, theme API 1.1): modules render a
                                 sanitised per-post aside here (e.g. an accepted-answer badge). The post + topic
                                 are passed as context; output is sanitised through the post-HTML allowlist. --}}
                            <x-slot-outlet name="topic.post.aside" :context="['post' => $post, 'topic' => $topic]" />

                            <livewire:forum.post-reactions :key="'react-'.$post->id"
                                :post-id="$post->id"
                                :topic-id="$post->topic_id"
                                :counts="$reactionCounts[$post->id] ?? []"
                                :viewer-type="$viewerReactions[$post->id] ?? null"
                                :can-react="$canReact" />

                            <footer class="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-3">
                                @if ($canBookmark)
                                    {{-- Member tool 2.1: save this post. --}}
                                    <livewire:forum.bookmark-button :key="'bm-post-'.$post->id" kind="post" :target-id="$post->id"
                                        :saved="($viewerBookmarks[$post->id] ?? false)" :can-save="$canBookmark" />
                                @endif
                                @can('update', $post)
                                    <x-ui.button :href="route('posts.edit', $post)" variant="subtle" size="sm">{{ __('common.edit') }}</x-ui.button>
                                @endcan
                                @can('delete', $post)
                                    {{-- De-weighted vs Edit (Pillar 3): a quiet, text-only destructive action that
                                         doesn't compete with the neighbouring Edit control. --}}
                                    <form method="POST" action="{{ route('posts.destroy', $post) }}" onsubmit="return confirm('{{ __('forum.confirm_delete_post') }}')">@csrf @method('DELETE')
                                        <x-ui.button type="submit" variant="danger-soft" size="sm">{{ __('common.delete') }}</x-ui.button>
                                    </form>
                                @endcan
                                {{-- Edit-history diff: only for EDITED posts the viewer can see (author of the post,
                                     or staff with post.history.view). open() re-asserts server-side. --}}
                                @php($ownPost = $post->user_id && $user && (int) $post->user_id === (int) $user->id)
                                @if ($post->edit_count > 0 && ($ownPost || $canViewHistory))
                                    <livewire:forum.post-history :key="'history-'.$post->id"
                                        :post-id="$post->id" :topic-id="$post->topic_id" :edit-count="$post->edit_count" />
                                @endif
                                @auth
                                    @if ($canReply)
                                        {{-- Quote-reply (M1): pre-fills the bottom composer with a blockquote +
                                             attribution and links the reply to this post; scrolls to the composer. --}}
                                        <x-ui.button :href="route('topics.show', $topic).'?quote='.$post->id.'#reply-composer'"
                                                     variant="ghost" size="sm" dusk="quote-post-{{ $post->id }}">
                                            {{ __('forum.quote') }}
                                        </x-ui.button>
                                    @endif
                                    <form method="POST" action="{{ route('reports.store') }}" class="ml-auto">@csrf
                                        <input type="hidden" name="post_id" value="{{ $post->id }}">
                                        <x-ui.button type="submit" variant="ghost" size="sm">
                                            <x-ui.icon name="flag" class="h-4 w-4" /> {{ __('forum.report') }}
                                        </x-ui.button>
                                    </form>
                                @endauth
                            </footer>
                        </div>
                    </div>
                </x-ui.card>
            @endforeach
        </div>

        <div>{{ $posts->links() }}</div>

        @if ($canModerate)
            {{-- Cross-page bulk moderation (P2-M4): the Alpine store + the floating action bar (posts context). --}}
            @include('partials.bulk-select-store')
            <livewire:forum.bulk-actions context="posts" :topic-id="$topic->id" />
        @endif

        @auth
            @if ($canReply)
                {{-- ?quote={id} (M1) pre-fills a quote-reply; ?canned={id} (T1, staff picker) pre-fills a canned reply. --}}
                <div id="reply-composer">
                    <livewire:forum.reply-composer :topic-id="$topic->id"
                        :quote="(int) request('quote') ?: null"
                        :canned="(int) request('canned') ?: null"
                        :key="'reply-composer-'.((int) request('quote')).'-'.((int) request('canned'))" />
                </div>
            @elseif ($topic->status === 'locked')
                <x-ui.card class="flex items-center gap-3 text-ink-muted">
                    <x-ui.icon name="lock" class="h-5 w-5 shrink-0 text-ink-subtle" />
                    <p class="text-sm">{{ __('forum.locked_no_replies') }}</p>
                </x-ui.card>
            @endif
        @else
            <x-ui.card class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-ink-muted">{{ __('forum.join_to_reply') }}</p>
                <x-ui.button :href="route('login')" size="sm">{{ __('forum.sign_in_to_reply') }}</x-ui.button>
            </x-ui.card>
        @endauth

        {{-- Overridable sandbox template (ADR-0038): a topic-footer note, rendered only when enabled. --}}
        <x-sandbox-template name="topic_footer"
            :data="['topic' => ['title' => $topic->title, 'reply_count' => (int) $topic->reply_count]]" />

        {{-- Discovery 3.3: related topics (share-a-tag, same-forum fallback; permission-safe). --}}
        @if (($related ?? collect())->isNotEmpty())
            <section class="mt-8 space-y-2" aria-label="{{ __('forum.related_topics') }}">
                <h2 class="px-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle font-sans">{{ __('forum.related_topics') }}</h2>
                <x-ui.card flush>
                    <ul class="divide-y divide-line">
                        @foreach ($related as $rel)
                            @include('discovery.partials.topic-line', ['topic' => $rel])
                        @endforeach
                    </ul>
                </x-ui.card>
            </section>
        @endif

        {{-- Theme Studio 1.3: configurable region — admin-placed widgets at the bottom of a topic. --}}
        <x-region name="topic_bottom" />
    </x-ui.container>
@endsection
