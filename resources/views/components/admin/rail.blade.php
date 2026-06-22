{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- The ACP icon rail (ACP v3 · v3-h, foundations §3): the top-level section switcher. Each entry links to a
     section's dashboard landing; the active section is marked with aria-current. Keyboard-operable (anchor list,
     visible focus ring) with an accessible name on the landmark. Renders vertically in the desktop rail and as a
     horizontal scroll strip inside the mobile drawer (orientation prop). --}}
@props(['sections' => [], 'orientation' => 'vertical'])
@php $horizontal = $orientation === 'horizontal'; @endphp

<nav aria-label="{{ __('admin.sections_label') }}">
    <ul class="{{ $horizontal ? 'flex gap-1 overflow-x-auto pb-1' : 'space-y-1' }}">
        @foreach ($sections as $section)
            <li @class(['shrink-0' => $horizontal])>
                <a href="{{ $section['url'] }}" wire:navigate @if ($section['active']) aria-current="page" @endif
                   title="{{ $section['label'] }}"
                   @class([
                       'flex flex-col items-center gap-1 rounded-lg px-1 py-2 text-[11px] font-medium leading-tight text-center',
                       'min-w-[4rem]' => $horizontal,
                       'bg-accent-soft text-accent-soft-ink' => $section['active'],
                       'text-ink-muted hover:bg-surface-sunken hover:text-ink' => ! $section['active'],
                       'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
                   ])>
                    <x-ui.icon :name="$section['icon']" class="h-5 w-5 shrink-0" />
                    <span>{{ $section['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
