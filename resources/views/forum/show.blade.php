{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => $forum->title.' · '.config('app.name', 'Hearth')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => $forum->title],
    ]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $forum->title }}</h1>
                @if ($forum->description)
                    <p class="mt-1 text-sm text-ink-muted">{{ $forum->description }}</p>
                @endif
            </div>
            @if ($canPost)
                <x-ui.button :href="route('topics.create', $forum)">
                    <x-ui.icon name="plus" class="h-4 w-4" /> New topic
                </x-ui.button>
            @endif
        </div>

        {{-- Sub-boards (ProBoards-style) — child forums above the topic table, reusing the shared forum row. --}}
        @if ($children->isNotEmpty())
            <section class="space-y-2">
                <h2 class="px-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">Sub-boards</h2>
                <x-ui.card flush>
                    <div class="divide-y divide-line">
                        @foreach ($children as $child)
                            @include('forum.partials.forum-row', ['forum' => $child])
                        @endforeach
                    </div>
                </x-ui.card>
            </section>
        @endif

        @if ($topics->isNotEmpty())
            {{-- Desktop: a real, info-dense topic table (Subject · Replies · Views · Last post). --}}
            <x-ui.card flush class="hidden overflow-hidden md:block">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-subtle">
                            <th scope="col" class="px-4 py-2.5">Subject</th>
                            <th scope="col" class="w-24 px-4 py-2.5 text-right">Replies</th>
                            <th scope="col" class="w-24 px-4 py-2.5 text-right">Views</th>
                            <th scope="col" class="w-52 px-4 py-2.5">Last post</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($topics as $topic)
                            @php($lastPage = max(1, (int) ceil(($topic->reply_count + 1) / 15)))
                            <tr class="align-top hover:bg-surface-sunken">
                                <td class="px-4 py-3">
                                    <div class="flex items-start gap-2.5">
                                        <x-ui.avatar :user="$topic->author" size="sm" class="mt-0.5 hidden shrink-0 lg:inline-flex" />
                                        <div class="min-w-0">
                                            @if ($topic->is_pinned || $topic->status === 'locked')
                                                <div class="mb-0.5 flex flex-wrap items-center gap-1.5">
                                                    @if ($topic->is_pinned)
                                                        <x-ui.badge variant="accent"><x-ui.icon name="pin" class="h-3 w-3" /> Pinned</x-ui.badge>
                                                    @endif
                                                    @if ($topic->status === 'locked')
                                                        <x-ui.badge variant="neutral"><x-ui.icon name="lock" class="h-3 w-3" /> Locked</x-ui.badge>
                                                    @endif
                                                </div>
                                            @endif
                                            <a href="{{ route('topics.show', $topic) }}" class="block font-semibold text-ink hover:text-accent">{{ $topic->title }}</a>
                                            <p class="text-xs text-ink-subtle">by {{ $topic->author?->display_name ?? $topic->author?->username ?? 'unknown' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right align-middle nums text-ink-muted">{{ number_format($topic->reply_count) }}</td>
                                <td class="px-4 py-3 text-right align-middle nums text-ink-muted">{{ number_format($topic->view_count) }}</td>
                                <td class="px-4 py-3 align-middle">
                                    @if ($topic->last_posted_at)
                                        {{-- The poster name carries the link affordance (accent + hover underline,
                                             always-distinct from the adjacent meta — WCAG 1.4.1). --}}
                                        <a href="{{ route('topics.show', ['topic' => $topic, 'page' => $lastPage]).($topic->last_post_id ? '#post-'.$topic->last_post_id : '') }}" class="group block">
                                            <span class="block truncate font-medium text-accent group-hover:underline">{{ $topic->lastPostUser?->display_name ?? $topic->lastPostUser?->username ?? 'unknown' }}</span>
                                            <span class="block text-xs text-ink-subtle nums">{{ $topic->last_posted_at->diffForHumans() }}</span>
                                        </a>
                                    @else
                                        <span class="text-xs text-ink-subtle">No replies yet</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>

            {{-- Mobile: the same topics reflow to stacked rows (no horizontal scrolling — brief hard rule). --}}
            <x-ui.card flush class="md:hidden">
                <div class="divide-y divide-line">
                    @foreach ($topics as $topic)
                        @php($lastPage = max(1, (int) ceil(($topic->reply_count + 1) / 15)))
                        <div class="p-4 hover:bg-surface-sunken">
                            @if ($topic->is_pinned || $topic->status === 'locked')
                                <div class="mb-0.5 flex flex-wrap items-center gap-1.5">
                                    @if ($topic->is_pinned)
                                        <x-ui.badge variant="accent"><x-ui.icon name="pin" class="h-3 w-3" /> Pinned</x-ui.badge>
                                    @endif
                                    @if ($topic->status === 'locked')
                                        <x-ui.badge variant="neutral"><x-ui.icon name="lock" class="h-3 w-3" /> Locked</x-ui.badge>
                                    @endif
                                </div>
                            @endif
                            <a href="{{ route('topics.show', $topic) }}" class="block font-semibold text-ink hover:text-accent">{{ $topic->title }}</a>
                            <p class="mt-0.5 text-sm text-ink-muted">by {{ $topic->author?->display_name ?? $topic->author?->username ?? 'unknown' }}</p>
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
                                            <a href="{{ route('topics.show', ['topic' => $topic, 'page' => $lastPage]).($topic->last_post_id ? '#post-'.$topic->last_post_id : '') }}" class="text-accent hover:underline">last by {{ $topic->lastPostUser?->display_name ?? $topic->lastPostUser?->username ?? 'unknown' }}</a>
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
                <x-ui.empty title="No topics here yet">
                    <x-slot:icon><x-ui.icon name="message" class="h-6 w-6" /></x-slot:icon>
                    @if ($canPost)
                        Be the first to start the conversation in this forum.
                        <div class="mt-4">
                            <x-ui.button :href="route('topics.create', $forum)">
                                <x-ui.icon name="plus" class="h-4 w-4" /> Start a topic
                            </x-ui.button>
                        </div>
                    @else
                        Check back soon — there’s nothing posted here right now.
                    @endif
                </x-ui.empty>
            </x-ui.card>
        @endif
    </x-ui.container>
@endsection
