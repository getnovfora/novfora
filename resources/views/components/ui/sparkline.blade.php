{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- A hand-authored inline-SVG area/line chart from a numeric series (T3 analytics) — no JS, no build step.
     DECORATIVE: aria-hidden, so a screen reader skips it and reads the accompanying data table instead.
     Props: series (list<int>), tone (accent|success|warn|danger → currentColor), height (px). Scales to its
     container width via preserveAspectRatio="none" + a non-scaling stroke so the line stays crisp. --}}
@props(['series' => [], 'tone' => 'accent', 'height' => 44])
@php
    $values = array_map('intval', array_values($series));
    $count = count($values);
    $peak = $count > 0 ? max($values) : 0;
    $w = 100;                 // viewBox width units (the SVG stretches to 100% of its container)
    $h = max(12, (int) $height);
    $pad = 2;                 // keep the line off the very top/bottom edges

    // Map each value to an (x,y) point — x evenly spaced, y inverted (0 = top), flat baseline when peak is 0.
    $points = [];
    foreach ($values as $i => $v) {
        $x = $count > 1 ? round(($i / ($count - 1)) * $w, 2) : 0.0;
        $y = $peak > 0 ? round($h - $pad - ($v / $peak) * ($h - 2 * $pad), 2) : ($h - $pad);
        $points[] = $x.','.$y;
    }
    // A single data point draws as a flat segment across the width (at that value's height).
    if ($count === 1) {
        $y = explode(',', $points[0])[1];
        $points = ['0,'.$y, $w.','.$y];
    }
    $line = implode(' ', $points);
    $area = $count > 0 ? 'M'.$points[0].' L'.$line.' L'.$w.','.$h.' L0,'.$h.' Z' : '';

    $color = match ($tone) {
        'success' => 'text-success',
        'warn' => 'text-warn',
        'danger' => 'text-danger',
        default => 'text-accent',
    };
@endphp
<svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" role="presentation" aria-hidden="true"
     style="height: {{ $h }}px; width: 100%;" {{ $attributes->class('block '.$color) }}>
    @if ($area !== '' && $peak > 0)
        <path d="{{ $area }}" fill="currentColor" opacity="0.12" />
        <polyline points="{{ $line }}" fill="none" stroke="currentColor" stroke-width="1.5"
                  stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
    @else
        {{-- Empty / all-zero range: a quiet flat baseline. --}}
        <line x1="0" y1="{{ $h - $pad }}" x2="{{ $w }}" y2="{{ $h - $pad }}"
              stroke="currentColor" stroke-width="1" opacity="0.25" vector-effect="non-scaling-stroke" />
    @endif
</svg>
