{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Alpine dropdown menu. Put a real <button> in the `trigger` slot and the menu items
     (<x-ui.dropdown-item>) in the default slot. align: right | left. Escape / outside-click close it. --}}
@props(['align' => 'right', 'width' => 'w-56'])
<div x-data="{ open: false }" @keydown.escape.stop="open = false" class="relative">
    {{-- The trigger slot is a real <button>; its click bubbles here to toggle, so it stays keyboard-operable. --}}
    <div @click="open = ! open" :aria-expanded="open.toString()" class="contents">
        {{ $trigger }}
    </div>

    <div x-show="open" x-cloak x-transition.origin.top.{{ $align }} @click.outside="open = false"
         @click="open = false" role="menu"
         class="absolute z-40 mt-2 {{ $width }} {{ $align === 'right' ? 'right-0' : 'left-0' }} origin-top-{{ $align }}
                rounded-lg border border-line bg-surface-raised shadow-md p-1">
        {{ $slot }}
    </div>
</div>
