<!-- SPDX-License-Identifier: Apache-2.0 -->
# HANDOFF → Code: apply the NovFora brand to the forum app

**Goal:** apply the locked "Indie Web Hearth + Nova" brand to the Laravel/Livewire app. Token *values* + self-hosted fonts + serif headings + favicon + a slate/indigo→semantic sweep. **Token NAMES are the THEME-API public contract — change values only, never rename.** Full rationale + palette: `brand/NovFora-Brand-Guidelines.md`.

Run cold on the VPS build box. Branch: `claude/brand-novfora-v1`. Effort: Sonnet is fine for the mechanical edits; **bump to Opus for the Blade sweep** (judgment — don't blanket-replace). Commit identity `Tommy Huynh <tommy@saturnhq.net>`, `-s` DCO, no AI trailers.

---

## Step 1 — Self-host the fonts (no CDN; preserves the privacy/offline-build value)

```bash
npm i @fontsource/inter @fontsource-variable/newsreader
```
At the TOP of `resources/css/app.css` (before `@import 'tailwindcss'` is fine, or in `resources/js/app.js`), add:
```css
@import '@fontsource/inter/400.css';
@import '@fontsource/inter/500.css';
@import '@fontsource/inter/600.css';
@import '@fontsource-variable/newsreader';
```
These bundle woff2 into the Vite build → served from our own origin, **no third-party request at runtime**. (Accepted tradeoff: the byte-reproducible build now includes the font binaries — note it in `DECISIONS.md`.)

In the `@theme` block of `app.css`, set the family tokens (keep system fallbacks):
```css
--font-sans: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'Noto Sans', sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';
--font-serif: 'Newsreader Variable', 'Newsreader', Georgia, 'Times New Roman', serif;
```
And add the **ember** semantic utilities to `@theme` (next to the other `--color-*` mappings):
```css
--color-ember: var(--ember);
--color-ember-ink: var(--ember-ink);
```

## Step 2 — Replace the semantic token VALUES (3 places)

Edit `resources/css/app.css`. Replace the values in **`:root` (light)**, the **`@media (prefers-color-scheme: dark) :root:not([data-theme='light'])`** block, **and** **`:root[data-theme='dark']`** (the two dark blocks are identical — change both). Names stay; values become:

**Light `:root`:**
```css
--surface:#F4EEE2; --surface-raised:#FCFAF4; --surface-sunken:#EBE3D3;
--ink:#221C13; --ink-muted:#5C5346; --ink-subtle:#6F6354;
--line:#E6DCCB; --line-strong:#D3C6AF;
--accent:#245FBB; --accent-ink:#FFFFFF; --accent-hover:#1E4F9E;
--accent-soft:#E5EDFB; --accent-soft-ink:#1C4A95;
--ember:#B5731F; --ember-ink:#FFFFFF;
--success:#2B774D; --success-soft:#DCEFE2; --success-ink:#1F6B43;
--warn:#8F6207; --warn-soft:#FAEFC9; --warn-ink:#785205;
--danger:#BE3A2B; --danger-soft:#FAE3DE; --danger-ink:#A12B1F; --danger-strong:#D2402E;
--focus:#245FBB;
```

**Both dark blocks:**
```css
--surface:#0B0B10; --surface-raised:#14151D; --surface-sunken:#08080C;
--ink:#F3E8DD; --ink-muted:#CFC9BE; --ink-subtle:#938C7E;
--line:#242230; --line-strong:#36333F;
--accent:#4D93F2; --accent-ink:#08121F; --accent-hover:#6FAAF7;
--accent-soft:#16223A; --accent-soft-ink:#9FC2F6;
--ember:#EBA94B; --ember-ink:#1A1206;
--success:#35B07A; --success-soft:#11271C; --success-ink:#7FD3A8;
--warn:#E0AE3F; --warn-soft:#2A2206; --warn-ink:#F0C766;
--danger:#E5705B; --danger-soft:#2E1411; --danger-ink:#F2A293; --danger-strong:#E8573F;
--focus:#6FAAF7;
```
Leave `--group-*`, `--novfora-*` aliases, radii, shadows, and motion tokens **unchanged** (optional: warm the dark shadow tint to `rgb(10 6 2 / .5)`). Add the two `--ember*` lines to each block as shown.

## Step 3 — Serif headings

Add to the base layer of `app.css` (after the `body` rule):
```css
h1, h2, h3, .font-display { font-family: var(--font-serif); font-weight: 600; letter-spacing: .2px; }
```
Verify no existing `font-sans` utility on headings overrides this where you want serif. The wordmark/brand component should use `.font-display`.

## Step 4 — Slate/indigo → semantic sweep (Opus; judgment)

The palette change only fully lands if hard-coded scale utilities move to semantic tokens. Find them:
```bash
grep -rnE '\b(bg|text|border|ring|from|to|via|fill|stroke)-(indigo|slate)-[0-9]{2,3}' resources/views | wc -l
grep -rnE '\b(bg|text|border|ring)-(indigo|slate)-[0-9]{2,3}' resources/views
```
Map intent → token (don't blind-replace):
- `*-indigo-600/500` used as the action color → `*-accent` (or `*-accent-hover`).
- `bg-slate-50/100`, `bg-white` panels → `bg-surface` / `bg-surface-raised` / `bg-surface-sunken`.
- `text-slate-900/700/500` → `text-ink` / `text-ink-muted` / `text-ink-subtle`.
- `border-slate-200/300` → `border-line` / `border-line-strong`.
- Warm-signature spots (brand/logo, the hero "home", a few highlights) → `text-ember` / `bg-ember`.
Keep the `--color-slate-*` / `--color-indigo-*` scales defined (other code/tests may reference them) but stop using them as the brand's primary surfaces/action.

## Step 5 — Favicon / app icon

```bash
cp brand/assets/novfora-favicon.svg public/favicon.svg
# PNG fallbacks (use sharp or rsvg-convert/inkscape on the box):
for s in 16 32 180 192 512; do rsvg-convert -w $s -h $s public/favicon.svg -o public/icons/icon-$s.png; done
```
Update the `<link rel="icon">`/apple-touch-icon + the PWA `manifest` icons in the layout to point at these. (PWA manifest is subpath-aware per ADR-0078 — keep that.)

## Step 6 — Gates (the correctness signal; cap output)

```bash
vendor/bin/pint --dirty
vendor/bin/phpstan analyse --memory-limit=1G 2>&1 | tail -n 30
php artisan test --compact 2>&1 | tail -n 40           # Pest; watch tests/Feature/Theme/A11yTest.php
php artisan dusk --group=theme 2>&1 | tail -n 40        # if the visual/theme Dusk group exists
```
Theme/A11y tests assert the literal strings `--novfora-accent`, `.skip-link`, `:focus-visible` and the `.novfora-prose` contract — all preserved here (values changed, names intact), so they should stay green. If any AA snapshot test pins old hexes, update the expected values (new ones are AA-verified in the guidelines).

## Step 7 — Commit

```bash
git config user.name "Tommy Huynh"; git config user.email tommy@saturnhq.net
git add -A
git commit -s -m "feat(theme): apply NovFora 'Indie Web Hearth + Nova' brand

Nova Blue leads (primary/links/focus), Ember Amber signature (--ember),
teal=success only; obsidian warm-dark + hearth-cream light; self-hosted
Newsreader (serif headings) + Inter (body); constellation-bubble favicon.
Token NAMES unchanged (THEME-API contract); values per brand/NovFora-Brand-Guidelines.md."
```

## Acceptance

- App builds (`npm run build`), boots, and visibly reads as the new brand in **both** light and dark.
- No CDN font requests at runtime (fonts served locally).
- Pint/Larastan/Pest green; a11y gate green; AA holds in both modes.
- Favicon is the constellation/ember mark.
- `grep -riE '(bg|text|border)-(indigo|slate)-' resources/views` returns only intentional residue (documented), not brand surfaces/actions.
