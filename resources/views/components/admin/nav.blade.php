{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- The ACP left navigation: a client-side quick-search over a built index of admin pages + settings
     labels (no server search engine — the SMF/Invision ergonomic), then the grouped link list. Rendered
     by <x-admin.shell> for both the desktop sidebar and the mobile drawer; all state is token-driven and
     keyboard-operable. --}}
@props(['groups' => [], 'searchIndex' => []])

<nav aria-label="Admin" class="space-y-1">
    {{-- Quick search (client-side filter; jumps straight to a page or a setting anchor). --}}
    <div x-data="{
            q: '',
            items: @js($searchIndex),
            get results() {
                const t = this.q.trim().toLowerCase();
                if (! t) return [];
                return this.items
                    .filter(i => (i.label + ' ' + i.group).toLowerCase().includes(t))
                    .slice(0, 8);
            },
            go() { if (this.results.length) window.location = this.results[0].url; },
         }"
         class="relative mb-2">
        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-subtle">
            <x-ui.icon name="search" class="h-4 w-4" />
        </span>
        <input type="search" x-model="q" @keydown.enter.prevent="go()" @keydown.escape="q = ''"
               aria-label="Search admin pages and settings" autocomplete="off"
               placeholder="Jump to…"
               class="w-full min-h-11 pl-9 pr-3 rounded-md bg-surface border border-line text-sm text-ink placeholder:text-ink-subtle focus:border-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">

        <div x-show="q.trim() !== ''" x-cloak
             class="absolute z-30 mt-1 w-full overflow-hidden rounded-md border border-line bg-surface-raised shadow-md">
            <template x-for="r in results" :key="r.url">
                <a :href="r.url"
                   class="flex items-center justify-between gap-3 px-3 py-2 text-sm text-ink hover:bg-surface-sunken">
                    <span x-text="r.label" class="truncate"></span>
                    <span x-text="r.group" class="shrink-0 text-xs text-ink-subtle"></span>
                </a>
            </template>
            <p x-show="results.length === 0" class="px-3 py-2 text-sm text-ink-subtle">No matches.</p>
        </div>
    </div>

    @foreach ($groups as $group)
        <div>
            <p class="px-3 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ $group['label'] }}</p>
            <ul class="space-y-0.5">
                @foreach ($group['items'] as $item)
                    <li>
                        <a href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif
                           class="flex items-center gap-2.5 min-h-11 px-3 rounded-md text-sm {{ $item['active'] ? 'bg-accent-soft text-accent-soft-ink font-medium' : 'text-ink-muted hover:bg-surface-sunken hover:text-ink' }}">
                            <x-ui.icon :name="$item['icon']" class="h-4 w-4 shrink-0" />
                            <span class="flex-1 truncate">{{ $item['label'] }}</span>
                            @if ($item['external'])
                                <x-ui.icon name="external" class="h-3.5 w-3.5 shrink-0 text-ink-subtle" />
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
