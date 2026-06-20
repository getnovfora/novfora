{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- The admin control panel shell (ACP v3 · v3-h, foundations §3): the Invision-style icon RAIL of sections →
     the active section's SIDEBAR of sub-pages → the page content. Wrap an admin page's content in
     <x-admin.shell title="…">…</x-admin.shell>. The shell is presentation only — authorization is enforced by
     the admin route middleware (admin.access + staff-2FA) and re-asserted inside Livewire SFCs. Feature pages
     are shell-agnostic: they render here regardless of which section owns them. Mobile (≤lg): the rail + section
     nav collapse into a drawer toggled by the "Menu" button. --}}
@props(['title' => null, 'description' => null])
@php
    $rail = \App\Admin\AdminNavigation::rail();
    $clusters = \App\Admin\AdminNavigation::sidebar();
    $searchIndex = \App\Admin\AdminNavigation::searchIndex();
    $searchUrl = route('admin.search');
@endphp

<x-ui.container size="lg" x-data="{ navOpen: false }">
    <div class="lg:grid lg:grid-cols-[4.5rem_14rem_minmax(0,1fr)] lg:gap-6">
        {{-- Mobile: heading row + the nav-drawer toggle. --}}
        <div class="lg:hidden mb-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-sm font-semibold text-ink">
                <x-ui.icon name="cog" class="h-5 w-5 text-ink-muted" /> {{ __('admin.title') }}
            </div>
            <button type="button" @click="navOpen = ! navOpen" :aria-expanded="navOpen.toString()" aria-controls="acp-mobile-nav"
                    aria-label="{{ __('admin.open_menu') }}"
                    class="inline-flex items-center gap-1.5 min-h-11 px-3 rounded-md border border-line text-sm font-medium text-ink-muted hover:bg-surface-sunken hover:text-ink">
                <x-ui.icon name="menu" class="h-4 w-4" /> {{ __('admin.menu') }}
            </button>
        </div>
        <div id="acp-mobile-nav" x-show="navOpen" x-cloak x-collapse class="lg:hidden mb-4 space-y-3 rounded-lg border border-line bg-surface-raised p-2">
            <x-admin.rail :sections="$rail" orientation="horizontal" />
            <x-admin.nav :clusters="$clusters" :search-index="$searchIndex" :search-url="$searchUrl" />
        </div>

        {{-- Desktop: the icon rail. --}}
        <aside class="hidden lg:block" aria-label="{{ __('admin.sections_label') }}">
            <div class="sticky top-20">
                <x-admin.rail :sections="$rail" />
            </div>
        </aside>

        {{-- Desktop: the active section's sidebar. --}}
        <aside class="hidden lg:block">
            <div class="sticky top-20">
                <a href="{{ route('forums.index') }}" class="mb-3 inline-flex items-center gap-1.5 text-sm text-ink-muted hover:text-ink">
                    <x-ui.icon name="arrow-left" class="h-4 w-4" /> {{ __('admin.back_to_forum') }}
                </a>
                <x-admin.nav :clusters="$clusters" :search-index="$searchIndex" :search-url="$searchUrl" />
            </div>
        </aside>

        {{-- Content. --}}
        <main class="min-w-0 space-y-5">
            @if ($title)
                <div class="space-y-1.5">
                    <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $title }}</h1>
                    @if ($description)
                        <p class="text-sm text-ink-muted max-w-2xl">{{ $description }}</p>
                    @endif
                </div>
            @endif
            {{ $slot }}
        </main>
    </div>
</x-ui.container>
