{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Button / link-button. variant: primary | ghost | subtle | danger | danger-ghost | danger-soft.
     size: sm | md | lg. `icon` → square icon-only button. Renders as <a> when `href` is set. Touch
     target ≥44px at md/lg. `danger-soft` is the QUIET destructive action (text-only until hover) — use it
     for a secondary destructive control that must not compete with its neighbouring primary action. --}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'icon' => false,
])
@php
    $base = 'inline-flex items-center justify-center gap-2 font-medium leading-none whitespace-nowrap rounded-md '
        .'text-center transition-[background-color,border-color,color,filter] disabled:opacity-50 '
        .'disabled:pointer-events-none aria-disabled:opacity-50 aria-disabled:pointer-events-none';

    $sizes = $icon
        ? ['sm' => 'h-9 w-9', 'md' => 'h-11 w-11', 'lg' => 'h-12 w-12']
        : ['sm' => 'min-h-9 px-3 text-sm', 'md' => 'min-h-11 px-4 text-sm', 'lg' => 'min-h-12 px-5 text-base'];

    $variants = [
        'primary' => 'bg-accent text-accent-ink hover:bg-accent-hover',
        'ghost' => 'border border-line text-ink bg-surface-raised hover:bg-surface-sunken hover:border-line-strong',
        'subtle' => 'bg-surface-sunken text-ink hover:bg-line',
        'danger' => 'bg-danger-strong text-white hover:brightness-110',
        'danger-ghost' => 'border border-line text-danger bg-surface-raised hover:bg-danger-soft hover:border-danger',
        'danger-soft' => 'text-danger bg-transparent hover:bg-danger-soft hover:text-danger-ink',
    ];

    $classes = $base.' '.($sizes[$size] ?? $sizes['md']).' '.($variants[$variant] ?? $variants['primary']);
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->class($classes)->merge(['type' => 'button']) }}>{{ $slot }}</button>
@endif
