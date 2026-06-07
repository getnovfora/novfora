{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- A switch (styled checkbox). Submits like a normal checkbox; degrades without JS. --}}
@props([
    'name',
    'checked' => false,
    'label' => null,
    'value' => '1',
    'id' => null,
])
@php
    $id = $id ?? $name;
@endphp
<label for="{{ $id }}" class="inline-flex items-center gap-3 min-h-11 cursor-pointer select-none">
    <span class="relative inline-flex shrink-0">
        <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" @checked($checked)
               {{ $attributes->class('peer sr-only') }}>
        <span class="block h-6 w-11 rounded-full bg-line-strong transition-colors peer-checked:bg-accent
                     peer-focus-visible:ring-2 peer-focus-visible:ring-accent peer-focus-visible:ring-offset-2
                     peer-focus-visible:ring-offset-surface"></span>
        <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></span>
    </span>
    @if ($label)
        <span class="text-sm text-ink">{{ $label }}</span>
    @endif
</label>
