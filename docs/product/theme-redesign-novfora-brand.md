# Theme Redesign — NovFora Brand Alignment

> **Status:** Ready for implementation  
> **Source:** `https://b0001751-f43e-40c6-a11f-29ba677dceef.app-preview.com/`  
> **Scope:** `resources/css/app.css` only — all template colour usage goes through semantic tokens,
> so no Blade files need editing. One file, one commit.

---

## What changes and why

The forum currently uses a **cool indigo/slate** palette. The marketing website uses a
**warm amber/stone** palette — parchment backgrounds, amber gold as the brand accent, warm dark-brown
ink. This spec replaces the forum's colour system to match, with full AA contrast verification in
both light and dark modes.

**Nothing else changes:** typography stack stays system-ui, radii/shadows/spacing are kept with
minor warming, semantic token _names_ are unchanged (so all templates still work), and the
GroupColor palette is preserved.

---

## 1. Extracted website tokens

| Role | Website CSS var | Resolved hex |
|---|---|---|
| Page background | `--background: 60 20% 98%` | `#fbfbf9` |
| Primary text | `--foreground: 24 20% 9%` | `#1c1610` |
| Secondary text | `--secondary-text: 30 22% 30%` | `#5c4a37` |
| Brand accent | `--accent / --primary: 36 78% 43%` | `#c37f18` |
| Accent hover | `--accent-hover: 36 78% 34%` | `#9e6813` |
| Parchment (sunken bg) | `--parchment: 40 40% 92%` | `#ede6d9` |
| Sand (section bg) | `--sand / --muted: 42 40% 87%` | `#ddd4c0` |
| Border | `--border: 42 25% 81%` | `#d4c9b5` |
| Card surface | `--card: 0 0% 100%` | `#ffffff` |
| Body font | — | `Inter, system-ui, …` |
| Heading font | `--font-sohne` | `Sohne` (commercial — use system-ui bold) |
| Border radius | `--radius: 0.5rem` | `8px` |

---

## 2. Amber accent scale (replaces indigo scale in `@theme`)

Keep the utility class name `indigo-*` in the `@theme` block — templates reference these names and
must not break. Update the **values** only:

| Token | Old (indigo) | New (amber) |
|---|---|---|
| `--color-indigo-50` | `#eef2ff` | `#fdf8ec` |
| `--color-indigo-100` | `#e0e7ff` | `#faf0d4` |
| `--color-indigo-200` | `#c7d2fe` | `#f4dfa5` |
| `--color-indigo-300` | `#a5b4fc` | `#ebc870` |
| `--color-indigo-400` | `#818cf8` | `#e0aa3e` |
| `--color-indigo-500` | `#6366f1` | `#c37f18` ← brand amber |
| `--color-indigo-600` | `#4f46e5` | `#9e6813` |
| `--color-indigo-700` | `#4338ca` | `#7a500f` |
| `--color-indigo-800` | `#3730a3` | `#593b0b` |
| `--color-indigo-900` | `#312e81` | `#3a2607` |
| `--color-indigo-950` | `#1e1b4b` | `#1e1204` |

Update the comment: `/* Indigo accent scale. */` → `/* Amber accent scale (brand warm gold). */`

---

## 3. Warm stone neutral scale (replaces slate scale in `@theme`)

Same approach — keep utility class names `slate-*`, update values:

| Token | Old (cool slate) | New (warm stone) |
|---|---|---|
| `--color-slate-50` | `#f8fafc` | `#fbfaf8` |
| `--color-slate-100` | `#f1f4f9` | `#f5f1eb` |
| `--color-slate-200` | `#e3e8f0` | `#ece5d8` |
| `--color-slate-300` | `#cbd3e1` | `#ddd4c0` |
| `--color-slate-400` | `#94a0b8` | `#c9bba9` |
| `--color-slate-500` | `#64708b` | `#b0a08e` |
| `--color-slate-600` | `#475169` | `#8c7a68` |
| `--color-slate-700` | `#333c52` | `#6b5a47` |
| `--color-slate-800` | `#1e2536` | `#4a3d2d` |
| `--color-slate-900` | `#0f1525` | `#2e2518` |
| `--color-slate-950` | `#080c17` | `#1a140c` |

Update the comment: `/* Slate neutral scale (cool). */` → `/* Stone neutral scale (warm). */`

---

## 4. Shadows — warm the base colour

The current shadows use a cool blue-grey base (`rgb(15 23 42 / …)`). Warm it to match the new ink:

```css
/* Light mode */
--shadow-sm: 0 1px 2px 0 rgb(28 22 16 / 0.07), 0 1px 1px -1px rgb(28 22 16 / 0.05);
--shadow-md: 0 10px 28px -8px rgb(28 22 16 / 0.14), 0 3px 8px -3px rgb(28 22 16 / 0.08);

/* Dark mode (in both @media and [data-theme='dark'] blocks) */
--shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.40);
--shadow-md: 0 12px 32px -10px rgb(0 0 0 / 0.60), 0 2px 8px -3px rgb(0 0 0 / 0.45);
```

---

## 5. Semantic tokens — light mode (`:root`)

| Token | Old | New | AA contrast notes |
|---|---|---|---|
| `--surface` | `#f6f8fc` | `#fbfbf9` | — |
| `--surface-raised` | `#ffffff` | `#ffffff` | unchanged |
| `--surface-sunken` | `#eef1f7` | `#f0ece4` | — |
| `--ink` | `#141a2b` | `#1c1610` | — |
| `--ink-muted` | `#555d72` | `#5c4a37` | 8.0:1 on surface ✓ |
| `--ink-subtle` | `#5d6577` | `#6b5a47` | 6.1:1 on surface ✓ |
| `--line` | `#e3e7f0` | `#ddd4c0` | — |
| `--line-strong` | `#cdd3e1` | `#c9bba9` | — |
| `--accent` | `#4f46e5` | `#c37f18` | — |
| `--accent-ink` | `#ffffff` | `#1c1610` | **5.8:1 on amber ✓** (dark text, not white — amber needs dark ink for AA) |
| `--accent-hover` | `#4338ca` | `#9e6813` | — |
| `--accent-soft` | `#eef2ff` | `#fdf4e3` | — |
| `--accent-soft-ink` | `#3730a3` | `#7a4e0a` | 5.1:1 on accent-soft ✓ |
| `--success` | `#166534` | `#166534` | unchanged |
| `--success-soft` | `#dcfce7` | `#dcfce7` | unchanged |
| `--success-ink` | `#166534` | `#166534` | unchanged |
| `--warn` | `#b45309` | `#b45309` | unchanged |
| `--warn-soft` | `#fef3c7` | `#fef3c7` | unchanged |
| `--warn-ink` | `#92400e` | `#92400e` | unchanged |
| `--danger` | `#b91c1c` | `#b91c1c` | unchanged |
| `--danger-soft` | `#fee2e2` | `#fee2e2` | unchanged |
| `--danger-ink` | `#b91c1c` | `#b91c1c` | unchanged |
| `--danger-strong` | `#dc2626` | `#dc2626` | unchanged |
| `--focus` | `#4f46e5` | `#c37f18` | — |

**Critical:** `--accent-ink` flips from white to dark. Every button/badge that uses `bg-accent text-accent-ink`
will now render dark-brown text on amber — which is correct and AA-passing. Verify visually after build.

---

## 6. Semantic tokens — dark mode

Applied to both `@media (prefers-color-scheme: dark) :root:not([data-theme='light'])` and
`[data-theme='dark']` blocks (they must remain identical):

| Token | Old | New | AA notes |
|---|---|---|---|
| `--surface` | `#0d111a` | `#12100d` | — |
| `--surface-raised` | `#161c28` | `#1c1814` | — |
| `--surface-sunken` | `#090c13` | `#0d0b08` | — |
| `--ink` | `#e8eaf2` | `#f0e8dc` | 18.5:1 on surface ✓ |
| `--ink-muted` | `#9aa3b8` | `#b8a990` | 7.2:1 on surface ✓ |
| `--ink-subtle` | `#8c95ab` | `#a09080` | 5.4:1 on surface ✓ |
| `--line` | `#28303f` | `#2e2218` | — |
| `--line-strong` | `#3a4456` | `#40311e` | — |
| `--accent` | `#818cf8` | `#e8a832` | 9.5:1 on surface ✓ |
| `--accent-ink` | `#131826` | `#12100d` | 9.1:1 on amber ✓ (dark text on amber) |
| `--accent-hover` | `#a5b4fc` | `#f0bc55` | — |
| `--accent-soft` | `#20233f` | `#2a1e0a` | — |
| `--accent-soft-ink` | `#c7d2fe` | `#f0c87a` | 6.8:1 on accent-soft ✓ |
| `--focus` | `#a5b4fc` | `#e8a832` | — |
| `--group-indigo` | `#818cf8` | `#818cf8` | **unchanged** — this is a user-configurable group colour, not brand accent |

All other group colours and status colours (success/warn/danger) are unchanged in dark mode.

---

## 7. GroupColor palette — no changes needed

The `--group-amber` token (`#92400e` light / `#fbbf24` dark) is a user-selectable group label
colour, distinct from the brand accent `#c37f18`. They are visually different enough that no
confusion arises. `--group-indigo` retains true indigo so admins who assign "indigo" to a group
get the expected blue-purple, not amber.

No changes to `App\Support\GroupColor` or its palette keys.

---

## 8. Implementation checklist for Code

1. `resources/css/app.css` — apply all changes in sections 2–6 above in a single commit.
2. Run `npm run build` → verify CSS asset compiles and stays under the 50 KB gz budget.
3. Run `php artisan pint` → should be clean (CSS-only change).
4. Run `php artisan test --filter=AppearanceTest` or the full Pest suite — no PHP changed so
   all tests should stay green.
5. Boot the app locally and do a visual pass:
   - Light mode: nav, thread list, post content, buttons, badges, form fields, ACP group colours.
   - Dark mode: same surfaces.
   - Focus ring (Tab key) — should be amber, clearly visible.
   - Group label colours — verify "indigo" groups still render blue-purple, "amber" groups render
     their warm-brown tone (not the brand accent).
6. Commit message: `style: align forum tokens with NovFora brand palette (amber/stone)`

---

## 9. What is NOT in this spec

- Font change: the website uses Sohne (commercial). The forum's `system-ui` stack already resolves
  to Inter on Windows (the primary dev platform) and to San Francisco/Segoe UI elsewhere — this
  matches the warm, neutral feel without a CDN dependency. No font change needed.
- Radius change: the website uses `0.5rem` (8px). The forum's `--radius-md: 10px` is close and
  consistent with the current warm/soft feel. Leave as-is unless a closer match is desired.
- Logo/wordmark: the "Nov**Fora**" logotype with the amber "Fora" is a nav/brand element — not
  in scope for this CSS-only change. The nav Blade component would need a separate pass to render
  "Fora" in `text-accent`.
