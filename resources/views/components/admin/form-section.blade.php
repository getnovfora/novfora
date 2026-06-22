{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- One settings-form section, so every ACP settings page reads with the same rhythm (Pillar 2 — "one
     form-layout system"): an optional heading + help line, a divider from the section above, and the fields
     as the slot. Use inside a `space-y-*` <form>:

       <x-admin.form-section heading="Identity" help="How the site presents itself." id="setting-general-site-name">
         <x-ui.input label="Site name" ... />
       </x-admin.form-section>

     Props: `heading`, `help`, `id` (search-jump anchor), `first` (drop the top divider on the first section).
     Tokens-only → dark + density correct. --}}
@props(['heading' => null, 'help' => null, 'first' => false, 'id' => null])
<section @if ($id) id="{{ $id }}" @endif
    {{ $attributes->class(['space-y-3', 'border-t border-line pt-5' => ! $first]) }}>
    @if ($heading)
        <div class="space-y-0.5">
            <h2 class="text-sm font-semibold text-ink">{{ $heading }}</h2>
            @if ($help)
                <p class="text-xs text-ink-subtle">{{ $help }}</p>
            @endif
        </div>
    @endif
    {{ $slot }}
</section>
