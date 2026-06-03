{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Notifications · '.config('app.name', 'Hearth')])

@section('content')
    <main style="max-width:42rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <h1>Notifications</h1>
            <span style="display:flex;gap:.5rem">
                <a href="{{ route('settings.notifications') }}" style="font-size:.85rem">Preferences</a>
                <form method="POST" action="{{ route('notifications.read-all') }}">@csrf
                    <button style="padding:.3rem .6rem;border:1px solid #bbb;border-radius:6px;background:#fff;cursor:pointer;font-size:.8rem">Mark all read</button></form>
            </span>
        </div>

        @forelse ($notifications as $n)
            @php($d = $n->data)
            @php($actor = $d['actors'][0]['username'] ?? 'Someone')
            @php($others = max(0, (int) ($d['count'] ?? 1) - 1))
            <div style="padding:.6rem .2rem;border-bottom:1px solid #f0f0f3;{{ $n->read_at ? 'opacity:.6' : 'font-weight:600' }};display:flex;justify-content:space-between;gap:1rem;align-items:center">
                <a href="{{ $d['url'] ?? route('notifications.index') }}" style="text-decoration:none;color:inherit">
                    @switch($d['event'] ?? '')
                        @case('reply')
                            {{ $actor }}@if ($others) and {{ $others }} {{ \Illuminate\Support\Str::plural('other', $others) }} @endif replied in “{{ $d['topic_title'] ?? 'a thread' }}”
                            @break
                        @case('mention')
                            {{ $actor }} mentioned you in “{{ $d['topic_title'] ?? 'a discussion' }}”
                            @break
                        @case('moderation')
                            You received a moderation notice
                            @break
                        @default
                            New notification
                    @endswitch
                </a>
                @unless ($n->read_at)
                    <form method="POST" action="{{ route('notifications.read', $n->id) }}">@csrf
                        <button style="border:0;background:none;color:#2d2a6b;cursor:pointer;font-size:.8rem">Mark read</button></form>
                @endunless
            </div>
        @empty
            <p style="color:#999">You're all caught up.</p>
        @endforelse

        <div style="margin-top:1rem">{{ $notifications->links() }}</div>
    </main>
@endsection
