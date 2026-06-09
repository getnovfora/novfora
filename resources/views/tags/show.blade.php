{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => '#'.$tag->name.' · '.config('app.name', 'Hearth')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => 'Tags', 'url' => route('tags.index')],
        ['label' => '#'.$tag->name],
    ]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-ink">#{{ $tag->name }}</h1>
                <p class="mt-1 text-sm text-ink-muted">
                    {{ number_format($tag->usage_count) }} {{ $tag->usage_count === 1 ? 'topic' : 'topics' }}
                </p>
            </div>
        </div>

        @if ($topics->isNotEmpty())
            <x-ui.card flush>
                <div class="divide-y divide-line">
                    @foreach ($topics as $topic)
                        @php($lastPage = max(1, (int) ceil(($topic->reply_count + 1) / 15)))
                        <div class="p-4 hover:bg-surface-sunken">
                            @if ($topic->is_pinned || $topic->status === 'locked' || $topic->prefix || $topic->tags->isNotEmpty())
                                <div class="mb-1 flex flex-wrap items-center gap-1.5">
                                    <x-forum.prefix-badge :prefix="$topic->prefix" />
                                    @foreach ($topic->tags as $t)
                                        <x-forum.tag-chip :tag="$t" />
                                    @endforeach
                                    @if ($topic->is_pinned)
                                        <x-ui.badge variant="accent"><x-ui.icon name="pin" class="h-3 w-3" /> Pinned</x-ui.badge>
                                    @endif
                                    @if ($topic->status === 'locked')
                                        <x-ui.badge variant="neutral"><x-ui.icon name="lock" class="h-3 w-3" /> Locked</x-ui.badge>
                                    @endif
                                </div>
                            @endif

                            <a href="{{ route('topics.show', $topic) }}"
                               class="block font-semibold text-ink hover:text-accent"
                               dusk="tag-show-topic-{{ $topic->id }}">{{ $topic->title }}</a>

                            <p class="mt-0.5 text-sm text-ink-muted">
                                in <a href="{{ route('forums.show', $topic->forum) }}"
                                      class="text-accent hover:underline">{{ $topic->forum->title }}</a>
                                &middot; by <x-ui.user-name :user="$topic->author" />
                            </p>

                            <dl class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-subtle">
                                <div class="flex items-center gap-1">
                                    <dt class="sr-only">Replies</dt>
                                    <dd class="nums font-medium text-ink-muted">{{ number_format($topic->reply_count) }}</dd><span>replies</span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <dt class="sr-only">Views</dt>
                                    <dd class="nums font-medium text-ink-muted">{{ number_format($topic->view_count) }}</dd><span>views</span>
                                </div>
                                @if ($topic->last_posted_at)
                                    <div class="flex items-center gap-1">
                                        <dt class="sr-only">Last post</dt>
                                        <dd>
                                            <a href="{{ route('topics.show', ['topic' => $topic, 'page' => $lastPage]).($topic->last_post_id ? '#post-'.$topic->last_post_id : '') }}"
                                               class="text-accent hover:underline">last by <x-ui.user-name :user="$topic->lastPostUser" /></a>
                                            <span class="nums">· {{ $topic->last_posted_at->diffForHumans() }}</span>
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            <div>{{ $topics->links() }}</div>
        @else
            <x-ui.card flush>
                <x-ui.empty title="No topics with this tag">
                    <x-slot:icon><x-ui.icon name="tag" class="h-6 w-6" /></x-slot:icon>
                    No visible topics carry this tag yet.
                </x-ui.empty>
            </x-ui.card>
        @endif
    </x-ui.container>
@endsection
