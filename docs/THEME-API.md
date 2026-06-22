<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora Theme API (developer override layer)

> **Status:** stable, **semver'd public contract** (CLAUDE.md / ADR-0009 §3.2). **API version: `1.0`**
> (`App\Theme\ThemeManager::API_VERSION`). A breaking change to the overridable-view set or manifest is a
> **major-version event**. The *visual* point-and-click configurator (DB `themes.settings` tokens) is Phase 3;
> this document covers the **developer Blade-override layer** shipped in Phase 1 (M4).

## Principle — no core edits, ever

A theme **never edits core files**. It is a filesystem package that *overrides* the views it wants to change;
everything else falls through to core. Upgrades therefore never clobber a theme, and a theme only carries the
handful of views it actually customises (XenForo-style diff inheritance, native to Blade's view finder).

## Resolution order

When NovFora renders a view it resolves it **active theme → parent theme → … → core**. The active theme's
`views/` directory is checked first; the first file found wins. So a theme shipping
`themes/<slug>/views/forum/topic.blade.php` replaces core's `resources/views/forum/topic.blade.php` with no
core change. Activation is config-only: set `NOVFORA_THEME=<slug>` (or `config('novfora.theme.active')`).

## Package layout

```
themes/<slug>/
  theme.json          # manifest (below)
  views/              # Blade overrides, mirroring core's resources/views/ tree
  assets/             # optional; built with Vite and shipped PREBUILT (no Node on the host)
```

### Manifest (`theme.json`)

```jsonc
{
  "slug": "acme/aurora",     // unique; the directory under themes/
  "name": "Aurora",
  "version": "1.2.0",        // the theme's own semver
  "api_version": "^1.0",     // the NovFora THEME API major(s) it targets — checked before a core upgrade
  "parent": "acme/base"      // optional: inherit (fall back to) another theme, then core
}
```

`api_version` participates in the **same pre-upgrade compatibility check as modules** (ADR-0008): a theme
targeting an incompatible API major is disabled with a clear report, never silently broken.

## Overridable views (the public surface, v1.0)

Any view under `resources/views/` may be overridden, but the **stable, supported** override points are:

| Slot | View | Notes |
|---|---|---|
| Page shell | `layouts.app` | Must keep the a11y floor (below). |
| Forum index | `forum.index`, `forum.partials.forum-row` | |
| Forum view | `forum.show` | |
| Topic view | `forum.topic` | Keep the SEO `@push('head')` block or re-provide it. |
| Composers (shell) | `forum.create-topic`, `forum.edit-post` | The editor island itself is JS; restyle via CSS. |
| Search | `search.index` | |
| Notifications | `notifications.index`, `settings.notifications` | |
| Auth | `auth.*` | |
| Mail | `mail.notification` | |

New supported slots are added in **minor** versions; removing or renaming one is a **major**.

## Accessibility floor (ADR-0009 §3.3 / ADR-0016) — restyle, don't remove

Core bakes in a WCAG 2.1 AA floor that themes **may restyle but must not strip**:

- a **skip link** (`<a href="#main" class="skip-link">`) and a single `#main` landmark in `layouts.app`;
- a **visible focus** rule (`:focus-visible`) and a screen-reader-only utility (`.sr-only`);
- **AA-contrast design tokens** as CSS custom properties (`--novfora-fg`/`--novfora-bg`/`--novfora-accent`/…),
  the home for the Phase 3 visual configurator. Default combinations meet AA (≥ 4.5:1 body, ≥ 3:1 UI).

A theme that overrides `layouts.app` must preserve the skip link, the `#main` landmark, and focus visibility.

## Assets

Themes ship **prebuilt** CSS/JS (Vite) so the baseline host needs no Node runtime. Reference them from the
overridden `layouts.app` (or a partial) as the theme sees fit.

---

# Component library (`<x-ui.*>`) — the design-system reference

> NovFora's presentational components live under `resources/views/components/ui/`. They are **tokens-only**
> (semantic utilities — `bg-surface`, `text-ink`, `border-line`, `accent`, …), so they render correctly in
> **light + dark** and at **comfortable + compact density** with no `dark:` variants and no per-call work.
> Motion honours `prefers-reduced-motion` globally. Reuse these instead of bespoke markup so polish stays
> consistent and can't silently regress. (Design-Polish Program, Pillar 1.)

These are **presentational**: no business logic, no data fetching. State (sort, page, open/closed, selection)
is driven by the host — a Livewire component, an Alpine island, or a plain controller.

## Inventory

### Layout & identity
| Component | Purpose | Key props |
|---|---|---|
| `x-ui.container` | Centred page-width wrapper with responsive gutters. | `size`: `sm` (≈28rem) · `md` (≈48rem) · `lg` (forum width, default) · `xl` |
| `x-ui.card` | Raised content surface. | `flush` (drop the default padding) |
| `x-ui.avatar` | User avatar with deterministic initials fallback. | `user` \| `name`+`src`; `size`: `xs…xl`; `guest` |
| `x-ui.user-name` | Username with staff/group colour + optional link. | `user`, `link`, `fallback` |
| `x-ui.staff-flair` | Derived staff-role badge (gated by a setting). | `user` |
| `x-ui.online-badge` | Presence dot (opt-in aware). | `user` |
| `x-ui.breadcrumbs` | Breadcrumb trail. | `items` (array of `['label','url']`) |
| `x-ui.tabs` | Tab strip. | `items` |
| `x-ui.icon` | Inline SVG from the single icon set. | `name`, `class` (sizing, default `h-5 w-5`) |

### Form controls
| Component | Purpose | Key props |
|---|---|---|
| `x-ui.field` | Label + help + required + **error** wrapper for arbitrary inputs. | `label`, `for`, `hint`, `error`, `required` |
| `x-ui.input` | Labelled text input (wraps `field`). | `label`, `name`, `type`, `hint`, `required` |
| `x-ui.select` | Labelled select. | `label`, `name`, `hint`, `required` |
| `x-ui.textarea` | Labelled textarea. | `label`, `name`, `hint`, `rows`, `required` |
| `x-ui.toggle` | Accessible on/off switch. | `name`, `checked`, `label`, `value` |
| `x-ui.button` | Button / link-button (≥44px touch target at `md`/`lg`). | `variant`: `primary` · `ghost` · `subtle` · `danger` · `danger-ghost`; `size`: `sm`·`md`·`lg`; `href`; `icon` |

### Feedback & overlay
| Component | Purpose | Key props / variants |
|---|---|---|
| `x-ui.alert` | Inline message block (`role="alert"`). | `variant`: `info` · `success` · `warn` · `danger`; `title` |
| `x-ui.badge` | Small status pill. | `variant`: `neutral` · `accent` · `success` · `warn` · `danger` |
| `x-ui.modal` | Dialog (Alpine-driven, `x-cloak`). | `name`, `title`, `maxWidth` |
| `x-ui.dropdown` + `x-ui.dropdown-item` | Popover menu. | `align`, `width` / `href` |

### Data & state
| Component | Purpose | Key props |
|---|---|---|
| `x-ui.table` | Responsive data-table shell (host-driven sort + pagination). | `label`, `sticky`, `dense`, `hover` |
| `x-ui.empty` | Friendly empty state. | `icon`, `title` (+ supporting copy / actions as the slot) |
| `x-ui.skeleton` | Loading placeholder (motion-safe pulse, `aria-hidden`). | `lines` |

## State coverage — the contract

Every list / table / page surface should define all four states. The canonical components are:

| State | Component | Notes |
|---|---|---|
| **Empty** | `x-ui.empty` | A clear title + a next action; never a bare "0 results". |
| **Loading** | `x-ui.skeleton` | Shape-matched placeholders; `aria-hidden`. Pair with a polite SR announcement (below). |
| **Error** | `x-ui.alert variant="danger"` (block) or `x-ui.field`'s `error` prop (field-level) | Recoverable message, not a stack trace. |
| **Success / populated** | the surface itself | — |

### Canonical loading recipe (Livewire, baseline-safe — no daemon)

Skeleton at rest is hidden; Livewire reveals it only while a request is in flight. Announce the *result*
(not "loading") to assistive tech with a polite live region, so screen-reader users hear the outcome:

```blade
{{-- result announcement: re-renders with the new count → announced politely --}}
<p class="sr-only" role="status" aria-live="polite">{{ $rows->total() }} results.</p>

{{-- loading placeholder: hidden at rest + SSR; shown only during the request --}}
<div wire:loading.delay.class.remove="hidden" class="hidden" aria-hidden="true">
    <x-ui.skeleton :lines="6" />
</div>

{{-- live results: hidden while loading so the skeleton stands in --}}
<div wire:loading.delay.class="hidden">
    @forelse ($rows as $row) … @empty <x-ui.empty title="Nothing here yet" /> @endforelse
</div>
```

> This recipe is established here in Pillar 1; the consuming surfaces apply it — the ACP data tables in
> **Pillar 2 (Slice 4)** and the member directory / profile in **Pillar 3 (Slice 5)** — so the foundation
> slice stays presentational and the application lands with each surface's own branch.

## `x-ui.table` in depth

A presentational shell — it never sorts or paginates, it only carries the chrome:

```blade
<x-ui.table label="Members" sticky>
    <x-slot:head>
        <tr>
            <th><button type="button" wire:click="sortBy('name')">Name ▲</button></th>
            <th>Posts</th>
        </tr>
    </x-slot:head>

    @foreach ($members as $m)
        <tr><td>{{ $m->name }}</td><td class="nums">{{ $m->posts }}</td></tr>
    @endforeach
</x-ui.table>

{{ $members->links() }}   {{-- pagination is host-driven, beneath the table --}}
```

- **Density-aware** — cell padding uses the spacing scale (arbitrary variants `[&_td]:px-3` …), so it tightens
  under the global compact density automatically. `dense` adds a tighter, density-independent option.
- **Sticky header** (`sticky`) for tall, vertically-scrolled tables; the head sits on a raised strip.
- **Responsive** — the wrapper scrolls horizontally on narrow viewports and is **keyboard-focusable**
  (`tabindex="0"`), satisfying axe's `scrollable-region-focusable`.
- **Labelled** — `label` renders an sr-only `<caption>` and names the scroll region (`role="region"`).
- **Dark-mode-correct** — borders/strips/hover all resolve through tokens.

## Motion tokens

Standard durations + easings so transitions read as one product (defined in `app.css`). The
`prefers-reduced-motion` block neutralises them globally, so honouring reduced motion needs no per-call work.

| Token | Value | Use |
|---|---|---|
| `--dur-fast` | 100ms | micro state changes (hover/active) |
| `--dur-base` | 160ms | default UI transition |
| `--dur-slow` | 240ms | entrances (dropdowns/popovers/menus) |
| `--ease-standard` | `cubic-bezier(.2,0,0,1)` | general-purpose |
| `--ease-entrance` | `cubic-bezier(0,0,.2,1)` | decelerate-in |
| `--ease-exit` | `cubic-bezier(.4,0,1,1)` | accelerate-out |

Consume via the convenience utilities **`.transition-base`** / **`.transition-emphasis`**, or arbitrary
values: `class="transition duration-[var(--dur-base)] ease-[var(--ease-standard)]"`.
