{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Shared chrome for the user settings pages: page heading + a consistent tab bar + a content slot.
     Settings pages render <x-settings.shell title="…">…</x-settings.shell> inside @section('content'). --}}
@props(['title' => null])
@php
    $tabs = [
        ['label' => 'Profile', 'url' => route('settings.profile'), 'active' => request()->routeIs('settings.profile')],
        ['label' => 'Appearance', 'url' => route('settings.appearance'), 'active' => request()->routeIs('settings.appearance')],
        ['label' => 'Notifications', 'url' => route('settings.notifications'), 'active' => request()->routeIs('settings.notifications')],
        ['label' => 'Security', 'url' => route('settings.two-factor'), 'active' => request()->routeIs('settings.two-factor')],
    ];
@endphp
<x-ui.container size="md" class="space-y-6">
    <h1 class="text-2xl font-semibold tracking-tight text-ink">Settings</h1>
    <x-ui.tabs :items="$tabs" />
    <div class="space-y-4">
        @if ($title)
            <h2 class="text-lg font-semibold text-ink">{{ $title }}</h2>
        @endif
        {{ $slot }}
    </div>
</x-ui.container>
