{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- The admin control panel shell (ACP v1, PART 1): a persistent grouped left nav + the page content.
     Wrap an admin page's content in <x-admin.shell title="…">…</x-admin.shell>. Authorization is enforced
     by the admin/system + admin route middleware (admin.access + staff-2FA) AND, for Livewire SFCs,
     re-asserted inside the component — the shell is presentation only. Mobile (≤lg): the nav collapses to
     a drawer toggled by the "Admin menu" button (the 360px gate). --}}
@props(['title' => null, 'description' => null])
@php
    $navGroups = \App\Admin\AdminNavigation::groups();
    $searchIndex = \App\Admin\AdminNavigation::searchIndex();
@endphp

<x-ui.container size="xl" x-data="{ navOpen: false }">
    <div class="lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-8">
        {{-- Mobile: heading row + the nav-drawer toggle. --}}
        <div class="lg:hidden mb-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-sm font-semibold text-ink">
                <x-ui.icon name="cog" class="h-5 w-5 text-ink-muted" /> Admin
            </div>
            <button type="button" @click="navOpen = ! navOpen" :aria-expanded="navOpen.toString()" aria-controls="acp-mobile-nav"
                    class="inline-flex items-center gap-1.5 min-h-11 px-3 rounded-md border border-line text-sm font-medium text-ink-muted hover:bg-surface-sunken hover:text-ink">
                <x-ui.icon name="menu" class="h-4 w-4" /> Menu
            </button>
        </div>
        <div id="acp-mobile-nav" x-show="navOpen" x-cloak x-collapse class="lg:hidden mb-4 rounded-lg border border-line bg-surface-raised p-2">
            <x-admin.nav :groups="$navGroups" :search-index="$searchIndex" />
        </div>

        {{-- Desktop: sticky sidebar. --}}
        <aside class="hidden lg:block">
            <div class="sticky top-20">
                <a href="{{ route('forums.index') }}" class="mb-3 inline-flex items-center gap-1.5 text-sm text-ink-muted hover:text-ink">
                    <x-ui.icon name="arrow-left" class="h-4 w-4" /> Back to forum
                </a>
                <x-admin.nav :groups="$navGroups" :search-index="$searchIndex" />
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
