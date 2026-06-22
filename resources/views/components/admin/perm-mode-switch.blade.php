{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Simple / Advanced segmented switch for the permission editor homes (ADR-0089). Default Simple; the choice
     is persisted SERVER-SIDE by App\Support\PermMode (a year-long cookie set when ?mode= is chosen), so it
     survives navigation with no JS — these are plain ?mode= links and the server remembers the last pick. --}}
@props(['mode' => 'simple'])
<div role="group" aria-label="{{ __('admin.perms.mode_label') }}"
     class="inline-flex overflow-hidden rounded-md border border-line">
    <a href="{{ request()->fullUrlWithQuery(['mode' => 'simple']) }}"
       dusk="perm-mode-simple"
       @class([
           'min-h-9 px-3 inline-flex items-center text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
           'bg-accent-soft text-accent-soft-ink' => $mode === 'simple',
           'text-ink-muted hover:bg-surface-sunken hover:text-ink' => $mode !== 'simple',
       ])
       @if ($mode === 'simple') aria-current="true" @endif>{{ __('admin.perms.mode_simple') }}</a>
    <a href="{{ request()->fullUrlWithQuery(['mode' => 'advanced']) }}"
       dusk="perm-mode-advanced"
       @class([
           'min-h-9 px-3 inline-flex items-center border-l border-line text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
           'bg-accent-soft text-accent-soft-ink' => $mode === 'advanced',
           'text-ink-muted hover:bg-surface-sunken hover:text-ink' => $mode !== 'advanced',
       ])
       @if ($mode === 'advanced') aria-current="true" @endif>{{ __('admin.perms.mode_advanced') }}</a>
</div>
