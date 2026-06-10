{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Spike P2 — the coalesced digest email. One message summarising a user's pending notifications for the
     period, instead of one email per event. $items = list<DigestQueueItem>; $unsubscribeUrl = signed link. --}}
<p>Hello,</p>

<p>Here's what you missed on {{ config('app.name', 'Hearth') }}:</p>

<ul>
    @foreach ($items as $item)
        @php($p = $item->payload ?? [])
        <li>
            @switch($item->event_type)
                @case('reply')
                    {{ $item->actor_username ?? 'Someone' }} replied in
                    <strong>{{ $p['topic_title'] ?? 'a thread you follow' }}</strong>.
                    @break
                @case('mention')
                    {{ $item->actor_username ?? 'Someone' }} mentioned you in
                    <strong>{{ $p['topic_title'] ?? 'a discussion' }}</strong>.
                    @break
                @case('reaction')
                    {{ $item->actor_username ?? 'Someone' }} reacted to your post in
                    <strong>{{ $p['topic_title'] ?? 'a discussion' }}</strong>.
                    @break
                @case('pm.received')
                    {{ $item->actor_username ?? 'Someone' }} sent you a message.
                    @break
                @case('follow')
                    {{ $item->actor_username ?? 'Someone' }} started following you.
                    @break
                @case('moderation')
                    A moderator posted a notice on your account.
                    @break
                @default
                    You have a new notification.
            @endswitch
            @if (! empty($p['url']))
                — <a href="{{ $p['url'] }}">view</a>
            @endif
        </li>
    @endforeach
</ul>

<hr>
<p style="color:#888;font-size:.85rem">
    You're receiving this digest because of your notification preferences on {{ config('app.name', 'Hearth') }}.
    <a href="{{ $unsubscribeUrl }}">Unsubscribe from digests</a> or adjust them in your notification settings.
    On a shared host, email is best-effort — if these arrive late or in spam, ask your administrator about a
    transactional email provider.
</p>
