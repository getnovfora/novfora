{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Shared chrome for the user settings pages: page heading + a left sidebar nav + a content slot.
     Settings pages render <x-settings.shell title="…">…</x-settings.shell> inside @section('content').
     BUG-016: the ten destinations used to render through the shared flex-wrap <x-ui.tabs>, which spilled
     onto a SECOND ROW at desktop width. They now live in a vertical sidebar (a horizontal scroll strip on
     mobile) — always on ONE axis. Scoped here on purpose: x-ui.tabs is shared and stays unchanged. --}}
@props(['title' => null])
@php
    $tabs = [
        ['label' => 'Profile', 'url' => route('settings.profile'), 'active' => request()->routeIs('settings.profile')],
        ['label' => 'Groups', 'url' => route('settings.primary-group'), 'active' => request()->routeIs('settings.primary-group')],
        ['label' => 'Appearance', 'url' => route('settings.appearance'), 'active' => request()->routeIs('settings.appearance')],
        ['label' => 'Preferences', 'url' => route('settings.preferences'), 'active' => request()->routeIs('settings.preferences')],
        ['label' => 'Notifications', 'url' => route('settings.notifications'), 'active' => request()->routeIs('settings.notifications')],
        ['label' => 'Ignored', 'url' => route('settings.ignore-list'), 'active' => request()->routeIs('settings.ignore-list')],
        ['label' => 'Security', 'url' => route('settings.two-factor'), 'active' => request()->routeIs('settings.two-factor')],
        ['label' => 'Linked accounts', 'url' => route('settings.linked-accounts'), 'active' => request()->routeIs('settings.linked-accounts')],
        ['label' => 'API tokens', 'url' => route('settings.api-tokens'), 'active' => request()->routeIs('settings.api-tokens')],
        ['label' => 'Account', 'url' => route('settings.account'), 'active' => request()->routeIs('settings.account')],
    ];
@endphp
<x-ui.container size="lg" class="space-y-6">
    <h1 class="text-2xl font-semibold tracking-tight text-ink">Settings</h1>

    <div class="grid gap-6 sm:grid-cols-[14rem_1fr]">
        <nav aria-label="Settings" dusk="settings-nav">
            <ul class="flex gap-1 overflow-x-auto pb-1 sm:flex-col sm:gap-0.5 sm:overflow-visible sm:pb-0">
                @foreach ($tabs as $tab)
                    <li class="shrink-0 sm:shrink-0">
                        <a href="{{ $tab['url'] }}" @if ($tab['active']) aria-current="page" @endif
                           @class([
                               'block whitespace-nowrap rounded-md px-3 min-h-11 content-center text-sm font-medium transition-colors',
                               'bg-accent-soft text-accent-soft-ink' => $tab['active'],
                               'text-ink-muted hover:bg-surface-sunken hover:text-ink' => ! $tab['active'],
                               'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
                           ])>{{ $tab['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="min-w-0 space-y-4">
            @if ($title)
                <h2 class="text-lg font-semibold text-ink">{{ $title }}</h2>
            @endif
            {{ $slot }}
        </div>
    </div>
</x-ui.container>
