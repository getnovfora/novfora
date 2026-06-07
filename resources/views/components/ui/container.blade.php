{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Centered page-width wrapper with responsive gutters. size: sm | md | lg | xl. --}}
@props(['size' => 'lg'])
@php
    $widths = [
        'sm' => 'max-w-md',    // auth / narrow forms (~28rem)
        'md' => 'max-w-3xl',   // settings / reading (~48rem)
        'lg' => 'max-w-5xl',   // forum index / lists (~64rem)
        'xl' => 'max-w-6xl',   // wide staff tables (~72rem)
    ];
@endphp
<div {{ $attributes->class('mx-auto w-full px-4 sm:px-6 '.($widths[$size] ?? $widths['lg'])) }}>{{ $slot }}</div>
