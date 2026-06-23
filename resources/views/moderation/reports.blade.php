{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Reports · '.config('app.name', 'NovFora')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Forums', 'url' => route('forums.index')],
        ['label' => 'Moderation', 'url' => route('moderation.dashboard')],
        ['label' => 'Reports'],
    ]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Reports</h1>
            <p class="text-sm text-ink-muted">Open reports from the community, awaiting a moderator.</p>
        </div>

        <x-ui.tabs :items="[
            ['label' => 'Dashboard', 'url' => route('moderation.dashboard')],
            ['label' => 'Queue', 'url' => route('moderation.queue')],
            ['label' => 'Reports', 'url' => route('moderation.reports'), 'active' => true],
        ]" />

        <div class="space-y-2.5">
            @forelse ($reports as $report)
                @php($card = $cards[$report->id] ?? ['canSee' => false])
                <x-ui.card class="space-y-3">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="font-medium text-ink">
                                {{ class_basename($report->reportable_type) }}
                                <span class="text-ink-muted nums">#{{ $report->reportable_id }}</span>
                            </p>
                            <p class="mt-0.5 text-sm text-ink-muted">
                                reported by <x-ui.user-name :user="$report->reporter" fallback="system" />
                                @if ($card['canSee'] && $card['author'])
                                    · post by <x-ui.user-name :user="$card['author']" :link="true" />
                                @endif
                            </p>
                        </div>
                        <form method="POST" action="{{ route('reports.resolve', $report->id) }}"
                              class="flex shrink-0 items-center gap-2">
                            @csrf
                            <x-ui.button type="submit" name="action" value="resolved" size="sm">
                                <x-ui.icon name="check" class="h-4 w-4" /> Resolve
                            </x-ui.button>
                            <x-ui.button type="submit" name="action" value="dismissed" size="sm" variant="ghost">Dismiss</x-ui.button>
                        </form>
                    </div>

                    @if ($report->reason)
                        <p class="rounded-md bg-surface-sunken px-3 py-2 text-sm text-ink-muted">
                            <span class="font-medium text-ink-subtle">Reason:</span> {{ $report->reason }}
                        </p>
                    @endif

                    @if ($card['canSee'])
                        {{-- Context: the reported post (server-sanitised body_html_cache) + a link to it in its
                             topic. Only rendered when the viewer may see the forum — no private-club leak. --}}
                        <div class="overflow-hidden rounded-md border border-line">
                            <div class="flex items-center justify-between gap-2 border-b border-line bg-surface-sunken px-3 py-1.5 text-xs text-ink-subtle">
                                <span class="min-w-0 truncate">in “{{ $card['topic']->title }}”</span>
                                <a href="{{ $card['permalink'] }}" class="shrink-0 font-medium text-accent hover:underline">View in topic &rarr;</a>
                            </div>
                            <div class="novfora-prose max-h-48 overflow-y-auto px-3 py-2 text-sm">{!! $card['post']->body_html_cache !!}</div>
                        </div>

                        {{-- The moderator actions THIS viewer is permitted — each wired to the existing route +
                             gated by the same policy/engine the route enforces (server-side is authoritative). --}}
                        @if ($card['canModerateTopic'] || $card['canEditPost'] || $card['canDeletePost'] || $card['canWarn'])
                            <div class="flex flex-wrap items-center gap-2 border-t border-line pt-3">
                                @if ($card['canModerateTopic'])
                                    <form method="POST" action="{{ route('topics.pin', $card['topic']) }}">@csrf
                                        <x-ui.button type="submit" variant="ghost" size="sm">
                                            <x-ui.icon name="pin" class="h-4 w-4" /> {{ $card['topicPinned'] ? __('forum.unpin') : __('forum.pin') }}
                                        </x-ui.button>
                                    </form>
                                    <form method="POST" action="{{ route('topics.lock', $card['topic']) }}">@csrf
                                        <x-ui.button type="submit" variant="ghost" size="sm">
                                            <x-ui.icon name="lock" class="h-4 w-4" /> {{ $card['topicLocked'] ? __('forum.unlock') : __('forum.lock') }}
                                        </x-ui.button>
                                    </form>
                                @endif
                                @if ($card['canEditPost'])
                                    <x-ui.button :href="route('posts.edit', $card['post'])" variant="subtle" size="sm">
                                        <x-ui.icon name="pencil" class="h-4 w-4" /> Edit post
                                    </x-ui.button>
                                @endif
                                @if ($card['canDeletePost'])
                                    <form method="POST" action="{{ route('posts.destroy', $card['post']) }}" onsubmit="return confirm('{{ __('forum.confirm_delete_post') }}')">@csrf @method('DELETE')
                                        <x-ui.button type="submit" variant="danger-ghost" size="sm">Delete post</x-ui.button>
                                    </form>
                                @endif
                                @if ($card['canModerateTopic'])
                                    @if ($moveTargets->count() > 1)
                                        {{-- Move the topic to another board (existing topics.move route). --}}
                                        <form method="POST" action="{{ route('topics.move', $card['topic']) }}" class="flex items-center gap-1">@csrf
                                            <label class="sr-only" for="move-{{ $report->id }}">Move topic to board</label>
                                            <select id="move-{{ $report->id }}" name="forum_id"
                                                    class="min-h-9 rounded-md border border-line bg-surface px-2 text-xs text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                                                @foreach ($moveTargets as $target)
                                                    <option value="{{ $target->id }}" @selected($target->id === $card['topic']->forum_id)>{{ $target->title }}</option>
                                                @endforeach
                                            </select>
                                            <x-ui.button type="submit" variant="ghost" size="sm">Move</x-ui.button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('topics.destroy', $card['topic']) }}" onsubmit="return confirm('{{ __('forum.confirm_trash_topic') }}')">@csrf @method('DELETE')
                                        <x-ui.button type="submit" variant="danger-ghost" size="sm">Delete topic</x-ui.button>
                                    </form>
                                @endif
                                @if ($card['canWarn'] && $warningTypes->isNotEmpty())
                                    {{-- Warn the post's author (existing warnings.store route; rank-guarded server-side). --}}
                                    <form method="POST" action="{{ route('warnings.store', $card['author']) }}" class="flex items-center gap-1">@csrf
                                        <label class="sr-only" for="warn-{{ $report->id }}">Warning type</label>
                                        <select id="warn-{{ $report->id }}" name="warning_type_id"
                                                class="min-h-9 rounded-md border border-line bg-surface px-2 text-xs text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                                            @foreach ($warningTypes as $type)
                                                <option value="{{ $type->id }}">{{ $type->label }}</option>
                                            @endforeach
                                        </select>
                                        <x-ui.button type="submit" variant="danger-ghost" size="sm">Warn author</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    @endif
                </x-ui.card>
            @empty
                <x-ui.card>
                    <x-ui.empty title="No open reports">
                        <x-slot:icon><x-ui.icon name="flag" class="h-6 w-6" /></x-slot:icon>
                        Everything is clear — new reports from the community will show up here.
                    </x-ui.empty>
                </x-ui.card>
            @endforelse
        </div>

        <div>{{ $reports->links() }}</div>
    </x-ui.container>
@endsection
