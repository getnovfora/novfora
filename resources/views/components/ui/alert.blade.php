{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Inline message block. variant: info | success | warn | danger. --}}
@props(['variant' => 'info', 'title' => null])
@php
    $variants = [
        'info' => 'bg-accent-soft text-accent-soft-ink',
        'success' => 'bg-success-soft text-success-ink',
        'warn' => 'bg-warn-soft text-warn-ink',
        'danger' => 'bg-danger-soft text-danger-ink',
    ];
@endphp
<div role="alert" {{ $attributes->class('rounded-lg px-4 py-3 text-sm '.($variants[$variant] ?? $variants['info'])) }}>
    @if ($title)
        <p class="font-semibold mb-0.5">{{ $title }}</p>
    @endif
    <div>{{ $slot }}</div>
</div>
