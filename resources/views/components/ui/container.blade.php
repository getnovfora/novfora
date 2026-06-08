{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Centered page-width wrapper with responsive gutters. size: sm | md | lg | xl. --}}
@props(['size' => 'lg'])
@php
    $widths = [
        'sm' => 'max-w-md',    // auth / narrow forms (~28rem)
        'md' => 'max-w-3xl',   // settings / reading (~48rem)
        // forum index / lists — width follows the site Appearance setting via the --layout-max-width token
        // (default 64rem ≈ the old max-w-5xl); auth/settings/admin keep their fixed sizes.
        'lg' => 'max-w-[var(--layout-max-width,64rem)]',
        'xl' => 'max-w-6xl',   // wide staff tables (~72rem)
    ];
@endphp
<div {{ $attributes->class('mx-auto w-full px-4 sm:px-6 '.($widths[$size] ?? $widths['lg'])) }}>{{ $slot }}</div>
