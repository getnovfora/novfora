{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Notifications · '.config('app.name', 'NovFora')])

@section('content')
    <x-ui.container size="md" class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Notifications</h1>
            <div class="flex items-center gap-2">
                <x-ui.button :href="route('settings.notifications')" variant="ghost" size="sm">
                    <x-ui.icon name="cog" class="h-4 w-4" /> Preferences
                </x-ui.button>
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <x-ui.button type="submit" variant="subtle" size="sm">
                        <x-ui.icon name="check" class="h-4 w-4" /> Mark all read
                    </x-ui.button>
                </form>
            </div>
        </div>

        @if ($notifications->count())
            <x-ui.card flush>
                <ul class="divide-y divide-line">
                    @foreach ($notifications as $n)
                        @php($d = $n->data)
                        @php($actor = $d['actors'][0]['username'] ?? 'Someone')
                        @php($others = max(0, (int) ($d['count'] ?? 1) - 1))
                        <li @class([
                            'flex items-center gap-3 p-4 transition-colors hover:bg-surface-sunken',
                            'bg-accent-soft/40' => ! $n->read_at,
                        ])>
                            <span @class([
                                'mt-1 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full',
                                'bg-accent-soft text-accent-soft-ink' => ! $n->read_at,
                                'bg-surface-sunken text-ink-subtle' => $n->read_at,
                            ])>
                                @switch($d['event'] ?? '')
                                    @case('reply')
                                        <x-ui.icon name="reply" class="h-4 w-4" />
                                        @break
                                    @case('mention')
                                        <x-ui.icon name="message" class="h-4 w-4" />
                                        @break
                                    @case('reaction')
                                        <span aria-hidden="true" class="text-sm leading-none">{{ config('novfora.reactions.types.'.($d['reaction_type'] ?? '').'.emoji', '👍') }}</span>
                                        @break
                                    @case('pm.received')
                                        <x-ui.icon name="mail" class="h-4 w-4" />
                                        @break
                                    @case('follow')
                                        <x-ui.icon name="user" class="h-4 w-4" />
                                        @break
                                    @case('moderation')
                                        <x-ui.icon name="shield" class="h-4 w-4" />
                                        @break
                                    @default
                                        <x-ui.icon name="bell" class="h-4 w-4" />
                                @endswitch
                            </span>

                            <a href="{{ $d['url'] ?? route('notifications.index') }}"
                               @class([
                                   'min-w-0 flex-1 text-sm',
                                   'font-semibold text-ink' => ! $n->read_at,
                                   'text-ink-muted' => $n->read_at,
                               ])>
                                @switch($d['event'] ?? '')
                                    @case('reply')
                                        {{ $actor }}@if ($others) and {{ $others }} {{ \Illuminate\Support\Str::plural('other', $others) }} @endif replied in “{{ $d['topic_title'] ?? 'a thread' }}”
                                        @break
                                    @case('mention')
                                        {{ $actor }} mentioned you in “{{ $d['topic_title'] ?? 'a discussion' }}”
                                        @break
                                    @case('reaction')
                                        {{ $actor }}@if ($others) and {{ $others }} {{ \Illuminate\Support\Str::plural('other', $others) }} @endif reacted to your post in “{{ $d['topic_title'] ?? 'a discussion' }}”
                                        @break
                                    @case('pm.received')
                                        {{ $actor }} sent you a message
                                        @break
                                    @case('follow')
                                        {{ $actor }} started following you
                                        @break
                                    @case('moderation')
                                        You received a moderation notice
                                        @break
                                    @default
                                        New notification
                                @endswitch
                            </a>

                            @unless ($n->read_at)
                                <form method="POST" action="{{ route('notifications.read', $n->id) }}" class="shrink-0">
                                    @csrf
                                    <x-ui.button type="submit" variant="subtle" size="sm">Mark read</x-ui.button>
                                </form>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @else
            <x-ui.empty title="You’re all caught up">
                <x-slot:icon><x-ui.icon name="inbox" class="h-6 w-6" /></x-slot:icon>
                New replies, mentions, and notices will show up here.
            </x-ui.empty>
        @endif

        <div>{{ $notifications->links() }}</div>
    </x-ui.container>
@endsection
