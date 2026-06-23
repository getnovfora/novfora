<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
NovFora Brand Guidelines & Design-Token Spec — v1.0 (2026-06-23)
-->

# NovFora — Brand Guidelines & Design-Token Spec

**Identity:** *Indie Web Hearth + Nova.* **Status:** v1 (logo locked at concept level; production vectors pending a graphic designer). This document is the source of truth for color, type, logo usage, and the exact design tokens used to apply the brand to the forum app (`resources/css/app.css`) and the marketing site.

---

## 1 · Brand essence

NovFora is open‑source, **self‑hosted** forum software for people who want to **own their community** instead of renting space on a big platform. The feeling is a lit cabin on a hill under a starry sky: **warm and welcoming** (the hearth) and **hopeful and new** (the nova — a new star, a new gathering place; *novus + fora*).

**Positioning line:** Own your forum. Build your home.
**Descriptor:** Self‑hosted forum software for independent communities. Yours to own. Yours to shape. Here to stay.

**Pillars**

| Pillar | One‑liner |
|---|---|
| Indie Web Hearth | Warm. Welcoming. Yours. |
| Self‑Hosted Freedom | Your data. Your rules. |
| Built for Communities | Thoughtful spaces that scale. |
| Guided by Nova | Bright ideas. New gatherings. |
| Open Source, Open Future | Apache‑2.0, forever. |

## 2 · Voice & tone

Warm, direct, and craftsman‑like — a knowledgeable friend who self‑hosts, not a SaaS sales page. Plain language over jargon; confident without hype; generous with the *why*. Favor "you own it / it's yours / build it your way." Avoid platform‑speak ("synergy," "leverage," "disrupt"). Sentence case in UI. The serif gives headlines an editorial, slightly nostalgic warmth; keep body copy clean and practical.

---

## 3 · Logo

**The mark — Circular Constellation Bubble.** A circular chat/speech bubble holding a small constellation: an **Ember Amber hub** (the hearth) at the center, linked by faint dashed lines to a **Nova‑Blue star** and two **member dots** (the community gathered). It fuses every thread of the brand — conversation, gathering, hearth, nova.

**Variants** (in `brand/assets/`):

- `novfora-mark.svg` — primary, for **dark** surfaces (cream bubble outline, colored constellation).
- `novfora-mark-light.svg` — for **light** surfaces (obsidian outline).
- `novfora-mark-mono.svg` — single‑color (`currentColor`) for stamps, embroidery, one‑color print.
- `novfora-star.svg` — the **Ember star alone**: the smallest/most reduced mark and a great standalone glyph.
- `novfora-favicon.svg` — a self‑contained obsidian bubble + amber star for tabs/PWA.

**Lockup.** Mark + wordmark "NovFora" set in the serif (see §5). In running lockups the wordmark may carry an amber "Fora," or be single‑color; the mark carries the color when the wordmark is neutral.

**Clear space:** keep free space ≥ the height of the bubble's tail on all sides. **Minimum sizes:** full constellation mark ≥ 22 px; below that use `novfora-favicon.svg` or `novfora-star.svg` (the dashed lines disappear cleanly at small sizes — the star + hub carry it).

**Don'ts:** don't recolor the constellation outside the palette; don't add gradients/3D/shadow to the mark (a faint glow on dark is the only allowed effect); don't stretch, rotate, or crowd it; don't place the colored mark on a busy photo without a scrim.

> Production note: a graphic designer will redraw this into final, optimized vector files. Hand them `brand/assets/*` + `brand/novfora-logo-prompt.md` as the brief.

---

## 4 · Color

Two anchors carry the brand: **Ember Amber** (warm hearth/signature) and **Nova Blue** (cool spark / primary action). They sit on a **warm‑dark obsidian** range and a **hearth‑cream** light range. Green is demoted to a single **success** tone so it never dominates.

### Brand palette

| Token | Name | Hex | Role |
|---|---|---|---|
| Ember Amber | signature warm | `#EBA94B` | Logo, brand warmth, the "home" word, special highlights. **Not** a status color. |
| Nova Blue | primary / action | `#4D93F2` | Primary buttons, links, focus, "current." Leads the UI. |
| Hearth Cream | light ink / light bg | `#F3E8DD` | Text on dark; the light‑mode paper. |
| Obsidian | dark bg | `#0B0B10` | The dark surface. |
| Dusk Violet | accent / planned | `#8E76E6` | A category accent and the "planned" status. |
| Emerald | success only | `#35B07A` | Success status. The only green. |
| Gold | attention | `#D9A12A` | "Attention/needs‑review" status — distinct from Ember Amber. |
| Coral | issue / danger | `#E5705B` | Error/danger status. |

### Accent rule (important)

**Nova Blue leads; Ember Amber is the signature, not the primary button.** Primary CTAs, links, focus rings, and "current/active" use Nova Blue. Amber appears in the logo, the wordmark, the hero "home," and sparing warm highlights. Keep them ~80/20 in favor of blue in functional UI so the warmth reads as *accent*, not *theme*.

### Status legend

Success = Emerald · Current = Nova Blue · Planned = Dusk Violet · Attention = Gold · Issue = Coral · Later = neutral gray. Never rely on color alone — pair every status with an icon or label (WCAG 1.4.1).

### Accessibility

Every text/UI pair below is verified ≥ WCAG AA. Nova Blue deepens to `#245FBB` in light mode so it passes both as link text on cream and as a white‑label button. Borders are intentionally low‑contrast warm hairlines (a brand choice); affordance comes from fills + the focus ring, not the border.

---

## 5 · Typography

- **Display / headings — editorial serif.** `Newsreader` (or Spectral / Source Serif as fallbacks). Weights 500–700. Gives headlines the warm, editorial, slightly nostalgic voice. Used for h1–h3, the wordmark, big numbers/stats.
- **Body / UI — `Inter`.** This is the marketing site's current body font and stays the body face everywhere. Weights 400/500/600. Highly legible at forum density.
- **Monospace — system mono** (`ui-monospace, SFMono‑Regular, Menlo, Consolas`) for code, tokens, counts.
- **Numerals:** tabular figures for counts/stats (`font-variant-numeric: tabular-nums`).

**App font decision (flagged):** the forum app is currently zero‑font‑files / no‑CDN (a privacy + offline‑build value). To use Newsreader + Inter in the app, **self‑host the font files** (bundled locally, no third‑party request — privacy property preserved). This is the chosen approach; it adds the woff2 files to the build. The marketing site may load them from Google Fonts freely.

**Type scale** (unchanged from the app's existing scale): 13 / 14 / 16 / 18 / 22 / 28 px + a hero step, with comfortable line‑heights. Headings use the serif; everything else Inter.

---

## 6 · UI foundations

- **Surfaces / elevation.** One warm, unified elevation system both modes. Light = editorial matte print; dark = obsidian "shadow on a photograph." Shadows are warm‑tinted, not neutral; dark mode leans on a hairline + soft shadow rather than heavy drops.
- **Radii:** 6 / 10 / 16 px (existing) — soft corners are the warmth carrier. Pills for chips/avatars.
- **Borders:** low‑opacity warm hairlines (`--line`), subtle by design.
- **Glow (from Concept 2, used sparingly):** a soft colored halo is allowed on the primary button, category icon tiles, the logo on dark, and status dots. Keep it restrained — never neon, never on text.
- **Motion:** existing tokens (100 / 160 / 240 ms; standard/entrance/exit easings); honor `prefers-reduced-motion`.

---

## 7 · Design tokens — forum app (`resources/css/app.css`)

**Drop‑in replacement of token *values* only.** The semantic token **names are the THEME‑API public contract** (ADR‑0009 / `docs/THEME-API.md`) — do **not** rename. Replace the values in the three places they're defined: `:root` (light), the `@media (prefers-color-scheme: dark)` block, and `:root[data-theme='dark']`. Keep `--group-*` (GroupColor palette), `--novfora-*` aliases, radii, and motion tokens as they are (optionally warm `--group-amber` later).

> One addition: introduce **`--ember`** (and `--ember-ink`) as the warm‑signature token, since `--accent` is now Nova Blue. Use `--ember` for the logo region and warm highlights only.

### Light (`:root`)

```css
--surface:#F4EEE2; --surface-raised:#FCFAF4; --surface-sunken:#EBE3D3;
--ink:#221C13; --ink-muted:#5C5346; --ink-subtle:#6F6354;
--line:#E6DCCB; --line-strong:#D3C6AF;
--accent:#245FBB; --accent-ink:#FFFFFF; --accent-hover:#1E4F9E;
--accent-soft:#E5EDFB; --accent-soft-ink:#1C4A95;
--ember:#B5731F; --ember-ink:#FFFFFF;            /* warm signature (deepened for AA on light) */
--success:#2B774D; --success-soft:#DCEFE2; --success-ink:#1F6B43;
--warn:#8F6207;    --warn-soft:#FAEFC9;    --warn-ink:#785205;
--danger:#BE3A2B;  --danger-soft:#FAE3DE;  --danger-ink:#A12B1F; --danger-strong:#D2402E;
--focus:#245FBB;
```

### Dark (`@media prefers-color-scheme: dark` *and* `:root[data-theme='dark']`)

```css
--surface:#0B0B10; --surface-raised:#14151D; --surface-sunken:#08080C;
--ink:#F3E8DD; --ink-muted:#CFC9BE; --ink-subtle:#938C7E;
--line:#242230; --line-strong:#36333F;
--accent:#4D93F2; --accent-ink:#08121F; --accent-hover:#6FAAF7;
--accent-soft:#16223A; --accent-soft-ink:#9FC2F6;
--ember:#EBA94B; --ember-ink:#1A1206;            /* the constant brand amber */
--success:#35B07A; --success-soft:#11271C; --success-ink:#7FD3A8;
--warn:#E0AE3F;    --warn-soft:#2A2206;    --warn-ink:#F0C766;
--danger:#E5705B;  --danger-soft:#2E1411;  --danger-ink:#F2A293; --danger-strong:#E8573F;
--focus:#6FAAF7;
```

**Also:** warm the dark elevation shadow tint (e.g. `rgb(0 0 0 / .5)` → keep, or `rgb(10 6 2 / .5)` for warmth). Audit any hard‑coded `bg-indigo-*` / `bg-slate-*` utility usages in Blade and migrate them to semantic tokens (`bg-accent`, `bg-surface`, …) during application.

## 8 · Design tokens — marketing site

The site (React/shadcn) uses HSL custom properties. Map the same brand to its `:root` (light) and `.dark`:

```css
/* light */ --background:36 40% 92%; --foreground:36 30% 11%;
--primary:217 67% 44%;   /* Nova Blue #245FBB */ --primary-foreground:0 0% 100%;
--accent:36 78% 52%;     /* Ember Amber #EBA94B (warm signature) */
--ring:217 67% 44%;
/* dark */  --background:240 12% 5%; --foreground:35 38% 91%;
--primary:217 86% 63%;   /* Nova Blue #4D93F2 */ --accent:36 78% 60%;
```

Fonts: `Newsreader` for display, `Inter` for body. Provide the exact values when the site repo is mounted; the hexes above are authoritative.

## 9 · Imagery

Hero/feature imagery: the **cabin‑on‑a‑hill at dusk under the stars, with a small group gathered** — warm window light, milky‑way sky, sunset glow on the horizon. Always pair photos with a left‑to‑right obsidian scrim so headline text stays AA. Keep illustration flat and geometric; the constellation/star motif is the recurring graphic device.

## 10 · Application checklist

1. Self‑host `Newsreader` + `Inter` woff2 in the app; set serif on headings/wordmark, Inter on body.
2. Replace `app.css` token values per §7 (3 blocks); add `--ember`/`--ember-ink`.
3. Sweep Blade for hard‑coded `indigo-*` / `slate-*` utilities → semantic tokens.
4. Swap the favicon/app‑icon to `brand/assets/novfora-favicon.svg` + PNG exports.
5. Run the gates (Pint / Larastan / Pest / Dusk a11y) — the Theme/A11y tests assert `--novfora-accent`, `.skip-link`, focus‑visible; values changed, names preserved.
6. Apply §8 to the marketing site once mounted.
