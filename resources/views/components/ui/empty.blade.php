{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Friendly empty state. Pass an `icon` slot/prop, a `title`, and supporting copy + actions as the slot. --}}
@props(['icon' => null, 'title' => 'Nothing here yet'])
<div {{ $attributes->class('text-center py-12 px-4') }}>
    @if ($icon)
        <div class="mx-auto mb-3 inline-flex h-12 w-12 items-center justify-center rounded-full bg-surface-sunken text-ink-subtle">{!! $icon !!}</div>
    @endif
    <p class="text-base font-medium text-ink">{{ $title }}</p>
    @if (trim($slot) !== '')
        <div class="mt-1.5 text-sm text-ink-muted max-w-md mx-auto">{{ $slot }}</div>
    @endif
</div>
