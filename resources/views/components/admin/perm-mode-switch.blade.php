{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Simple / Advanced segmented switch for the permission editor homes (ADR-0089). Default Simple; the choice
     is remembered client-side (localStorage) — on a load with no ?mode, a saved "advanced" redirects once.
     Progressive: with no JS the two links still work (server default = Simple). --}}
@props(['mode' => 'simple'])
<div x-data="{
        init() {
            const url = new URL(window.location);
            if (! url.searchParams.has('mode') && localStorage.getItem('novfora.perm_mode') === 'advanced') {
                url.searchParams.set('mode', 'advanced');
                window.location.replace(url.toString());
            }
        },
        remember(mode) { localStorage.setItem('novfora.perm_mode', mode); },
     }"
     role="group" aria-label="{{ __('admin.perms.mode_label') }}"
     class="inline-flex overflow-hidden rounded-md border border-line">
    <a href="{{ request()->fullUrlWithQuery(['mode' => 'simple']) }}" @click="remember('simple')"
       dusk="perm-mode-simple"
       @class([
           'min-h-9 px-3 inline-flex items-center text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
           'bg-accent-soft text-accent-soft-ink' => $mode === 'simple',
           'text-ink-muted hover:bg-surface-sunken hover:text-ink' => $mode !== 'simple',
       ])
       @if ($mode === 'simple') aria-current="true" @endif>{{ __('admin.perms.mode_simple') }}</a>
    <a href="{{ request()->fullUrlWithQuery(['mode' => 'advanced']) }}" @click="remember('advanced')"
       dusk="perm-mode-advanced"
       @class([
           'min-h-9 px-3 inline-flex items-center border-l border-line text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
           'bg-accent-soft text-accent-soft-ink' => $mode === 'advanced',
           'text-ink-muted hover:bg-surface-sunken hover:text-ink' => $mode !== 'advanced',
       ])
       @if ($mode === 'advanced') aria-current="true" @endif>{{ __('admin.perms.mode_advanced') }}</a>
</div>
