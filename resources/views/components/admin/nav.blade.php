{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- The ACP section sidebar (ACP v3 · v3-h; Pillar 2 feel pass): the ACP quick-search, then the sub-page
     clusters for the ACTIVE rail section. The search is a client-side filter over a built index of pages +
     settings labels (instant jump — the SMF/Invision ergonomic); pressing Enter runs the full server-side
     search, which also matches members. The "/" key focuses it (when not already typing). All intra-ACP links
     use wire:navigate so switching sections morphs the page without a full reload — the persistent-shell fix
     for the audit's "feels unstable". Keyboard-operable and token-driven. --}}
@props(['clusters' => [], 'searchIndex' => [], 'searchUrl' => null])

<nav aria-label="{{ __('admin.section_nav_label') }}" class="space-y-1">
    {{-- Quick search: instant client-side dropdown (pages + settings); Enter submits the full search. --}}
    <form method="GET" action="{{ $searchUrl }}" role="search"
          x-data="{
              q: '',
              items: @js($searchIndex),
              get results() {
                  const t = this.q.trim().toLowerCase();
                  if (! t) return [];
                  return this.items
                      .filter(i => (i.label + ' ' + i.group).toLowerCase().includes(t))
                      .slice(0, 8);
              },
              focusOnSlash(e) {
                  if (e.key !== '/') return;
                  const a = document.activeElement;
                  if (a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.tagName === 'SELECT' || a.isContentEditable)) return;
                  // Only the visible instance grabs focus (the sidebar renders twice — desktop + mobile drawer).
                  if (this.$refs.searchInput && this.$refs.searchInput.offsetParent !== null) {
                      e.preventDefault();
                      this.$refs.searchInput.focus();
                  }
              },
          }"
          x-on:keydown.window="focusOnSlash($event)"
          class="relative mb-2">
        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-subtle">
            <x-ui.icon name="search" class="h-4 w-4" />
        </span>
        <input type="search" name="q" x-ref="searchInput" x-model="q" @keydown.escape="q = ''; $el.blur()"
               aria-label="{{ __('admin.search.label') }}" autocomplete="off"
               placeholder="{{ __('admin.search.placeholder') }}"
               class="w-full min-h-11 pl-9 pr-9 rounded-md bg-surface border border-line text-sm text-ink placeholder:text-ink-subtle focus:border-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
        {{-- Visible "/" shortcut affordance (desktop), hidden once the field has focus/content. --}}
        <span x-show="q === ''" class="pointer-events-none absolute inset-y-0 right-0 hidden items-center pr-2.5 lg:flex" aria-hidden="true">
            <kbd class="rounded border border-line bg-surface-raised px-1.5 py-0.5 text-[11px] font-medium text-ink-subtle">/</kbd>
        </span>

        <div x-show="q.trim() !== ''" x-cloak
             class="absolute z-30 mt-1 w-full overflow-hidden rounded-md border border-line bg-surface-raised shadow-md">
            <template x-for="r in results" :key="r.url">
                <a :href="r.url" wire:navigate
                   class="flex items-center justify-between gap-3 px-3 py-2 text-sm text-ink hover:bg-surface-sunken">
                    <span x-text="r.label" class="truncate"></span>
                    <span x-text="r.group" class="shrink-0 text-xs text-ink-subtle"></span>
                </a>
            </template>
            <p x-show="results.length === 0" class="px-3 py-2 text-sm text-ink-subtle">{{ __('admin.search.no_matches') }}</p>
        </div>
    </form>

    @foreach ($clusters as $cluster)
        <div>
            @if ($cluster['heading'])
                <p class="px-3 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ $cluster['heading'] }}</p>
            @endif
            <ul class="space-y-0.5">
                @foreach ($cluster['items'] as $item)
                    <li>
                        <a href="{{ $item['url'] }}" @unless ($item['external']) wire:navigate @endunless @if ($item['active']) aria-current="page" @endif
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
