{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- An item inside <x-ui.dropdown>. Renders as <a> when `href` is set, else a <button>. --}}
@props(['href' => null])
@php
    $classes = 'flex items-center gap-2 w-full text-left px-3 min-h-11 rounded-md text-sm text-ink hover:bg-surface-sunken';
@endphp
@if ($href)
    <a href="{{ $href }}" role="menuitem" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button role="menuitem" {{ $attributes->class($classes)->merge(['type' => 'button']) }}>{{ $slot }}</button>
@endif
