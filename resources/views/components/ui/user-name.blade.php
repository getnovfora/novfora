{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- ACP v2 — a user's name, coloured by their display group (App\Models\User::nameColor). When a coloured
     group applies, the name is wrapped in a <span> with the group's AA-safe colour TOKEN (inline style wins
     over a surrounding text-accent link colour); otherwise it is plain text that inherits the surrounding
     colour (--ink / link). Null user → the fallback. Purely inline — it never alters the parent markup or
     layout, so Dusk selectors and the name shown are unchanged for uncoloured users. --}}
@props(['user' => null, 'fallback' => 'unknown'])
@php
    $displayName = $user?->display_name ?? $user?->username ?? $fallback;
    $nameColor = $user?->nameColor();
@endphp
@if ($nameColor)<span style="color: {{ $nameColor }};">{{ $displayName }}</span>@else{{ $displayName }}@endif
