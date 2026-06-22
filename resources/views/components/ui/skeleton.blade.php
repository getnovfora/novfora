{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Loading placeholder bars (Design-Polish Program, Pillar 1). Decorative (aria-hidden); the pulse is
     gated by motion-safe AND neutralised by the global prefers-reduced-motion base rule. Pass `lines`
     for a multi-line text block (the last line is shortened); merged classes set the wrapper width, e.g.
     <x-ui.skeleton :lines="3" class="max-w-md" />. For a single sized block, override the bar via class. --}}
@props(['lines' => 1])
<div {{ $attributes->class('space-y-2') }} aria-hidden="true">
    @for ($i = 0; $i < (int) $lines; $i++)
        <div @class([
            'h-3 rounded bg-surface-sunken motion-safe:animate-pulse',
            'w-2/3' => (int) $lines > 1 && $i === (int) $lines - 1,
        ])></div>
    @endfor
</div>
