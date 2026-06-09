{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- A topic prefix as a small pill: coloured text + border in the prefix's palette token (AA-safe in both
     modes, via the --group-* CSS vars), or a neutral pill when no colour is set. Renders nothing for a null
     prefix, so call sites can pass an optional relation directly. --}}
@props(['prefix' => null])
@if ($prefix)
    @php($color = \App\Support\GroupColor::cssVar($prefix->color_token))
    <span {{ $attributes->class([
            'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium',
            'border-line bg-surface-sunken text-ink-muted' => ! $color,
        ]) }}
        @if ($color) style="color: {{ $color }}; border-color: {{ $color }};" @endif
        dusk="prefix-badge-{{ $prefix->id }}">{{ $prefix->label }}</span>
@endif
