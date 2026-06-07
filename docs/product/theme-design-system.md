<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# Hearth default theme — design system cheat sheet (implementer contract)

> The binding taste contract is [theme-design-brief.md](theme-design-brief.md). This file is the **how**:
> the exact tokens, components, and conventions every restyled page MUST use so the result is consistent.
> Appearance only — never change routes, controller data, validation, Livewire bindings, or markup that a
> test/Dusk selector depends on.

## Golden rules

1. **No inline `style=""` and no hex colours in templates.** Use Tailwind utilities backed by the tokens.
   (The one sanctioned exception already exists in `x-ui.avatar` for the per-name tint.)
2. **Colour via SEMANTIC utilities only** — they switch automatically in light/dark. **Never** use `dark:`
   variants and **never** use raw palette steps (`bg-slate-800`, `text-indigo-600`) for surfaces/text —
   those don't switch. Raw scales are allowed only for fixed accents inside `.hearth-prose` etc.
3. **Mobile-first.** Every page must be good at **360px**: no horizontal scroll, tables reflow to stacked
   rows, nav/actions wrap. Add `sm:`/`md:`/`lg:` upward.
4. **Touch targets ≥44px** on every interactive control (`min-h-11`, or the `x-ui` components which bake it in).
5. **Reuse `x-ui.*` components** instead of re-implementing buttons/inputs/badges/etc.
6. **Preserve the a11y floor and SEO blocks** — keep `@push('head')`, `id`/`name`/`for`, ARIA, `wire:` and
   form `action`/`@csrf`/`@method` exactly. Keep existing Dusk selectors (`.hearth-prose`, `@topic-title`,
   the "Post topic" button text, installer wizard bindings).

## Semantic colour tokens → utilities

| Role | utilities | use for |
|---|---|---|
| Page background | `bg-surface` | the page (body already set) |
| Raised surface | `bg-surface-raised` | cards, header, rows, menus |
| Sunken surface | `bg-surface-sunken` | wells, table headers, hover |
| Primary text | `text-ink` | headings, body |
| Muted text | `text-ink-muted` | secondary/meta text (AA) |
| Subtle text | `text-ink-subtle` | de-emphasised meta (still AA) |
| Hairline | `border-line`, `border-line-strong` | borders/dividers |
| Accent | `bg-accent text-accent-ink`, `text-accent`, `hover:bg-accent-hover` | primary actions, links |
| Accent soft | `bg-accent-soft text-accent-soft-ink` | active nav, info chips |
| Success/Warn/Danger | `text-success`/`-warn`/`-danger`; soft: `bg-*-soft text-*-ink` | status |
| Destructive fill | `bg-danger-strong text-white` | the one solid "Delete" button |

Numbers (counts/stats): add the `nums` class for tabular figures.
Type scale: `text-xs`(13) `text-sm`(14) `text-base`(16) `text-lg`(18) `text-xl`(22) `text-2xl`(28) `text-3xl`(34).
Radii: `rounded-md`(10) default; `rounded-sm`(6) small; `rounded-lg`(16) cards/wells; `rounded-full` pills/avatars.
Shadow: `shadow-sm` (rows/cards), `shadow-md` (menus/modals). That's the whole elevation set.

## Components (resources/views/components/ui/ unless noted)

- `<x-ui.container size="sm|md|lg|xl">` — centered page width + gutters. **Wrap every page's content.**
- `<x-ui.button variant="primary|ghost|subtle|danger|danger-ghost" size="sm|md|lg" :href icon>` — `:href` →
  `<a>`. `icon` → square icon button. `type="submit"` supported.
- `<x-ui.input label name type hint required>` / `<x-ui.textarea>` / `<x-ui.select>` — pull validation errors
  from the bag by `name`. `<x-ui.field label name>` wraps custom controls (radio groups).
- `<x-ui.badge variant>` · `<x-ui.alert variant title>` · `<x-ui.card>` · `<x-ui.empty title icon>`
- `<x-ui.avatar :user size="xs|sm|md|lg|xl">` (or `name`/`src`) — initials fallback baked in.
- `<x-ui.breadcrumbs :items=[['label','url'?]] >` — last item is the current page.
- `<x-ui.tabs :items=[['label','url','active','count'?]] >` — section/sub-nav.
- `<x-ui.dropdown align width>` with an `<x-slot:trigger><button>…</button></x-slot:trigger>` + `<x-ui.dropdown-item :href>`.
- `<x-ui.modal name title>` opened via `$dispatch('modal-open','name')`; `<x-ui.toggle name label checked>`.
- `<x-ui.icon name="…" class="h-5 w-5" />` — set: search bell menu close user users chevron-down/right check
  sun moon monitor pin lock reply flag plus arrow-left shield inbox message cog home logout.
- Settings pages: wrap content in `<x-settings.shell title="…">` (gives the heading + Profile/Appearance/
  Notifications/Security tabs).

## Page skeleton (copy this shape)

```blade
@extends('layouts.app', ['title' => $thing->title.' · '.config('app.name', 'Hearth')])
@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Forums', 'url' => route('forums.index')], ['label' => $thing->title]]" />
@endsection
@section('content')
    <x-ui.container size="lg" class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $thing->title }}</h1>
            <x-ui.button :href="route('…')"><x-ui.icon name="plus" class="h-4 w-4" /> New</x-ui.button>
        </div>
        {{-- … --}}
    </x-ui.container>
@endsection
```

Breadcrumbs render in a sub-bar under the header via `@section('breadcrumbs')` — move any inline breadcrumb
trail there. Empty states use `<x-ui.empty>` with friendly copy. Lists/tables: a `<x-ui.card flush>` holding
`divide-y divide-line` rows that **reflow** to stacked at mobile (don't use a real `<table>` that scrolls).

## Light/dark/density

Already wired globally by `layouts.app` + `app.css`. You do nothing per-page except use semantic tokens.
Don't add a second toggle. Don't set `data-theme`/`data-density` yourself.
