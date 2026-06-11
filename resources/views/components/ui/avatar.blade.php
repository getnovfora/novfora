{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- User avatar with an initials fallback (human avatars get visual priority). Pass a User via `user`, or
     `name` + optional `src`. size: xs | sm | md | lg | xl. The initials tint is derived deterministically
     from the name (an inline style attribute — allowed by the CSP style-src).
     `guest`: opt-in neutral silhouette for an ABSENT identity — a pseudonymised ("[Deleted]") author site
     passes :guest="$author === null" so it shows the guest avatar (ADR-0025) instead of a 'U' initials badge,
     WITHOUT changing the generic null default for ordinary optional-user uses elsewhere. --}}
@props([
    'user' => null,
    'name' => null,
    'src' => null,
    'size' => 'md',
    'guest' => false,
])
@php
    $name = $name ?? $user?->display_name ?? $user?->username ?? 'User';
    $src = $src ?? ($user?->avatar_path ? \Illuminate\Support\Facades\Storage::url($user->avatar_path) : null);

    $initials = \Illuminate\Support\Str::of($name)->trim()->explode(' ')
        ->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    if ($initials === '') {
        $initials = mb_strtoupper(mb_substr($name, 0, 1)) ?: '?';
    }

    $sizes = [
        'xs' => 'h-6 w-6 text-[0.6rem]',
        'sm' => 'h-8 w-8 text-xs',
        'md' => 'h-10 w-10 text-sm',
        'lg' => 'h-14 w-14 text-lg',
        'xl' => 'h-20 w-20 text-2xl',
    ];
    $box = $sizes[$size] ?? $sizes['md'];
    $hue = crc32($name) % 360;
@endphp
@if ($src)
    <img src="{{ $src }}" alt="{{ $name }}" loading="lazy"
        {{ $attributes->class('inline-block shrink-0 rounded-full object-cover bg-surface-sunken '.$box) }}>
@elseif ($guest && ! $user)
    <span aria-hidden="true"
        {{ $attributes->class('inline-flex shrink-0 items-center justify-center rounded-full bg-surface-sunken text-ink-subtle select-none '.$box) }}>
        <svg viewBox="0 0 24 24" fill="currentColor" style="width:60%;height:60%"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"/></svg>
    </span>
    <span class="sr-only">{{ __('[Deleted]') }}</span>
@else
    <span aria-hidden="true"
        {{ $attributes->class('inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white select-none '.$box) }}
        style="background:hsl({{ $hue }}deg 42% 42%)">{{ $initials }}</span>
    <span class="sr-only">{{ $name }}</span>
@endif
