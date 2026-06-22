{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Responsive data-table shell. The host supplies semantic markup; this only carries the chrome:

       <x-ui.table label="Members">
         <x-slot:head><tr><th>Name</th><th>Posts</th></tr></x-slot:head>
         <tr><td>…</td><td>…</td></tr>   (body rows)
       </x-ui.table>

     Props:
       label  — accessible name; renders an sr-only <caption> and labels the scroll region. Recommended.
       sticky — header sticks to the top while the container scrolls vertically (tall, scrolled tables).
       dense  — tighter cell padding, independent of the global density toggle.
       hover  — row hover highlight (default true).

     Sorting + pagination are HOST-driven: put sort links (or a <button wire:click=…>) in the head cells and a
     pagination control beneath the table. Cell padding uses the spacing scale, so it ALSO tightens under the
     global compact density. Tokens-only → dark-mode-correct. The scroll wrapper is keyboard-focusable so the
     table can be scrolled horizontally without a pointer (axe scrollable-region-focusable). --}}
@props(['label' => null, 'sticky' => false, 'dense' => false, 'hover' => true])
@php
    $pad = $dense
        ? '[&_th]:px-2.5 [&_th]:py-1.5 [&_td]:px-2.5 [&_td]:py-1.5'
        : '[&_th]:px-3 [&_th]:py-2.5 [&_td]:px-3 [&_td]:py-2.5';

    $table = trim(implode(' ', array_filter([
        'w-full border-collapse text-left text-sm align-middle',
        $pad,
        // Head: quiet uppercase labels on a raised strip, divided from the body.
        '[&_thead_th]:text-xs [&_thead_th]:font-semibold [&_thead_th]:uppercase [&_thead_th]:tracking-wide',
        '[&_thead_th]:text-ink-subtle [&_thead_th]:border-b [&_thead_th]:border-line-strong [&_thead_th]:bg-surface-raised',
        // Body: hairline row separators; ink text.
        '[&_tbody_tr]:border-t [&_tbody_tr]:border-line [&_td]:text-ink',
        $sticky ? '[&_thead_th]:sticky [&_thead_th]:top-0 [&_thead_th]:z-10' : null,
        $hover ? '[&_tbody_tr]:transition-colors [&_tbody_tr:hover]:bg-surface-sunken' : null,
    ])));
@endphp
<div {{ $attributes
        ->class('w-full overflow-x-auto rounded-lg border border-line')
        ->merge($label ? ['tabindex' => '0', 'role' => 'region', 'aria-label' => $label] : ['tabindex' => '0']) }}>
    <table class="{{ $table }}">
        @if ($label)
            <caption class="sr-only">{{ $label }}</caption>
        @endif
        @isset($head)
            <thead>{{ $head }}</thead>
        @endisset
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
