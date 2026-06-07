{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- User avatar with an initials fallback (human avatars get visual priority). Pass a User via `user`, or
     `name` + optional `src`. size: xs | sm | md | lg | xl. The initials tint is derived deterministically
     from the name (an inline style attribute — allowed by the CSP style-src). --}}
@props([
    'user' => null,
    'name' => null,
    'src' => null,
    'size' => 'md',
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
@else
    <span aria-hidden="true"
        {{ $attributes->class('inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white select-none '.$box) }}
        style="background:hsl({{ $hue }}deg 42% 42%)">{{ $initials }}</span>
    <span class="sr-only">{{ $name }}</span>
@endif
