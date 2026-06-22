{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Data-table shell (Design-Polish Program, Pillar 1). Presentational only — sorting / filtering /
     pagination are driven by the host (e.g. a Livewire component) through the slots. Cell padding uses
     spacing utilities, so the table follows the global density toggle (--spacing) for free.

     Usage:
       <x-ui.table :sticky="true">
           <x-slot:head>
               <tr><th>Member</th><th class="text-right">Posts</th><th><span class="sr-only">Actions</span></th></tr>
           </x-slot:head>
           @forelse ($rows as $r)
               <tr><td>…</td><td class="text-right nums">…</td><td>…</td></tr>
           @empty
               <tr><td colspan="3"><x-ui.empty title="No members match" /></td></tr>
           @endforelse
       </x-ui.table>

     `sticky` pins the header while a long table scrolls inside the wrapper. --}}
@props(['head' => null, 'sticky' => false])
<div {{ $attributes->class('overflow-x-auto rounded-lg border border-line bg-surface-raised') }}>
    <table class="w-full border-collapse text-left text-sm text-ink
                  [&_th]:px-3 [&_th]:py-2 [&_th]:font-semibold [&_th]:whitespace-nowrap
                  [&_td]:px-3 [&_td]:py-3 [&_td]:align-middle">
        @isset($head)
            <thead @class([
                'border-b border-line bg-surface-sunken text-xs uppercase tracking-wide text-ink-subtle',
                'sticky top-0 z-10' => $sticky,
            ])>
                {{ $head }}
            </thead>
        @endisset
        <tbody class="divide-y divide-line [&_tr:hover]:bg-surface">
            {{ $slot }}
        </tbody>
    </table>
</div>
