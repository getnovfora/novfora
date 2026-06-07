{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Small status pill. variant: neutral | accent | success | warn | danger. --}}
@props(['variant' => 'neutral'])
@php
    $variants = [
        'neutral' => 'bg-surface-sunken text-ink-muted',
        'accent' => 'bg-accent-soft text-accent-soft-ink',
        'success' => 'bg-success-soft text-success-ink',
        'warn' => 'bg-warn-soft text-warn-ink',
        'danger' => 'bg-danger-soft text-danger-ink',
    ];
@endphp
<span {{ $attributes->class('inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium '.($variants[$variant] ?? $variants['neutral'])) }}>{{ $slot }}</span>
