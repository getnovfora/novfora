{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Alpine modal dialog. Open it from anywhere with:
       window.dispatchEvent(new CustomEvent('modal-open', { detail: '<name>' }))
     or in Alpine: $dispatch('modal-open', '<name>'). Escape / backdrop / [data-modal-close] close it. --}}
@props(['name', 'title' => null, 'maxWidth' => 'max-w-lg'])
<div x-data="{ open: false }"
     x-on:modal-open.window="$event.detail === '{{ $name }}' && (open = true)"
     x-on:modal-close.window="open = false"
     x-on:keydown.escape.window="open = false"
     x-show="open" x-cloak
     class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4"
     role="dialog" aria-modal="true" @if ($title) aria-label="{{ $title }}" @endif>
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-[#0b0b10]/60" @click="open = false"></div>
    <div x-show="open" x-transition
         @click="$event.target.closest('[data-modal-close]') && (open = false)"
         class="relative w-full {{ $maxWidth }} bg-surface-raised border border-line rounded-t-xl sm:rounded-xl shadow-md p-5">
        @if ($title)
            <h2 class="text-lg font-semibold text-ink mb-2">{{ $title }}</h2>
        @endif
        {{ $slot }}
    </div>
</div>
