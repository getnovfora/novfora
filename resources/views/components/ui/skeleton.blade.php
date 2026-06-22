{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Loading placeholder — shimmer bars shown while content loads. `lines` = number of bars (default 3); the
     last bar is shortened so the block reads as a paragraph tail. The pulse is `motion-safe`, so the
     prefers-reduced-motion block leaves it as a static grey bar. `aria-hidden` keeps screen readers from
     announcing the placeholder — announce the load itself with an sr-only live region at the call site when
     it is user-initiated (e.g. a live search). Tokens-only, dark-mode-correct. --}}
@props(['lines' => 3])
@php($count = max(1, (int) $lines))
<div {{ $attributes->class('w-full space-y-2.5') }} aria-hidden="true" data-ui-skeleton>
    @for ($i = 0; $i < $count; $i++)
        <div @class([
            'h-3.5 rounded bg-surface-sunken motion-safe:animate-pulse',
            'w-2/3' => $i === $count - 1,
            'w-full' => $i !== $count - 1,
        ])></div>
    @endfor
</div>
