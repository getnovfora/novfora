{{-- SPDX-License-Identifier: Apache-2.0 --}}
@if (! empty($customBody ?? ''))
    {{-- T2 (ADR-0099): admin-customised body, rendered by the sandbox — every variable is auto-escaped and
         scripts/handlers are lint-blocked, so emitting it raw is safe. The footer below is always appended. --}}
    {!! $customBody !!}
@else
    <p>Hello,</p>

    @switch($event)
        @case('reply')
            <p>{{ $actor }} replied in <strong>{{ $payload['topic_title'] ?? 'a thread you follow' }}</strong>.</p>
            @break
        @case('mention')
            <p>{{ $actor }} mentioned you in <strong>{{ $payload['topic_title'] ?? 'a discussion' }}</strong>.</p>
            @break
        @case('reaction')
            <p>{{ $actor }} reacted to your post in <strong>{{ $payload['topic_title'] ?? 'a discussion' }}</strong>.</p>
            @break
        @case('pm.received')
            <p>{{ $actor }} sent you a message.</p>
            @break
        @case('follow')
            <p>{{ $actor }} started following you.</p>
            @break
        @case('moderation')
            <p>A moderator has issued a notice on your account. Please review it when you next sign in.</p>
            @break
        @default
            <p>You have a new notification.</p>
    @endswitch

    @if (! empty($payload['url']))
        <p><a href="{{ $payload['url'] }}">View it on {{ config('app.name', 'NovFora') }}</a></p>
    @endif
@endif

<hr>
<p style="color:#888;font-size:.85rem">
    You can adjust which emails you receive in your notification preferences.
    On a shared host, email is best-effort — if these arrive late or in spam, ask your administrator about a transactional email provider.
</p>
