{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- <x-ui.timestamp> — one semantic, accessible time rendering (UX-6 / NOV-95). Emits a real <time datetime="…">
     (machine-readable ISO) with a full localized date as its title tooltip; the visible text is relative
     ("2 hours ago") by default, or an absolute isoFormat when :relative="false" or a :format is given.
     Tabular-nums by default so timestamps don't jitter. Caller classes merge (e.g. class="block text-ink-subtle").

     :value    a DateTimeInterface (Carbon) — nothing renders for null.
     :relative true (default) → diffForHumans(); false → absolute LLL.
     :format   an explicit Carbon isoFormat token string (e.g. 'MMM YYYY') — wins over :relative. --}}
@props([
    'value' => null,
    'relative' => true,
    'format' => null,
])
@php
    $dt = $value instanceof \DateTimeInterface ? \Illuminate\Support\Carbon::instance($value) : null;
    $label = $dt
        ? ($format !== null ? $dt->isoFormat($format) : ($relative ? $dt->diffForHumans() : $dt->isoFormat('LLL')))
        : '';
@endphp
@if ($dt)<time datetime="{{ $dt->toIso8601String() }}" title="{{ $dt->isoFormat('LLLL') }}" {{ $attributes->class('nums') }}>{{ $label }}</time>@endif
