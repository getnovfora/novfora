<!-- SPDX-License-Identifier: Apache-2.0 -->

# Plan memo — v1.4 Phases 4A + 4B: Style engine + upgrade-safe customization (2026-07-17)

> Written per the kickoff's §2.1 (plan-before-phase; approval pre-granted by the kickoff itself).
> Sources: `FABLE-V1.4-KICKOFF-2026-07-17.md` §5, `ROADMAP-V1.3-V1.5-2026-07-02.md` Phase 4A/4B, and a
> four-agent recon of the existing theme/template system (ADR-0029/0032/0037/0038 layers).

## Ground truth (what exists — build on, don't rebuild)

- **DB style themes** (`site_themes`, ADR-0029/0037): accent + `tokens` JSON (**7 editable tokens** via
  `ThemeApi::editableTokens()` — 6 colors + radius, light-layer only), custom CSS, header/footer chrome,
  assets. Single-active invariant; compiled CSS `Cache::forever` under one global key; injected in
  `layouts/app.blade.php` after the Appearance accent, before the filesystem theme head.
- **Validation** is the security boundary: `StyleThemeManager::cleanTokens()` accepts a strict `#rrggbb`
  or `\d{1,4}(\.\d{1,2})?(px|rem|em)` only — a value can never contain `;`/`}`/`:`.
- **Filesystem child themes** (aurora/nebula) = whole-file Blade overrides via `FileViewFinder::prependLocation`
  — the "only tool" U11 replaces for targeted mods. Untouched by 4A/4B except as a proof surface.
- **Sandbox** (ADR-0038): bespoke restricted template language (`app/Theme/Sandbox/` — allowlist tokenizer,
  data-only context, 10 pure helpers, auto-escape, skeleton lint, bounded). `TemplateContract::VERSION 1.0.0`
  with 4 overridable keys; overrides in `site_templates` (no base tracking, no history, no merge — `revert()`
  is the only tool). Upgrades never reconcile stored sources against changed defaults.
- **No diff/merge library** in the app dependency tree (`sebastian/diff` is dev-only, and not diff3).
- **Users** carry appearance prefs as plain columns (`color_mode`, `density`, …) written by
  `AppearanceController` (no-JS-safe POST form); no per-user theme reference exists.
- **Brand lock** (`brand/NovFora-Brand-Guidelines.md`): Nova Blue leads (`#4D93F2`, deepened `#245FBB`
  light), Ember Amber signature (~80/20 blue), Emerald `#35B07A` is the **only** green (success-only);
  semantic token **names are the public contract** — values may change, names may not.

## U9 — Rich style-property system (NOV-107, branch `claude/v14-u9-style-props`)

1. **Typed/grouped property registry** — `ThemeApi::styleProperties()`: groups **Surfaces**
   (surface, surface-raised, surface-sunken), **Text** (ink, ink-muted, ink-subtle), **Lines** (line,
   line-strong), **Brand accents** (ember, ember-ink), **Status** (success/-soft/-ink, warn/-soft/-ink,
   danger/-soft/-ink/-strong), **Shape** (radius). Types: `color` | `length`. Every key maps to a CSS
   custom property that **already exists in `app.css`** (the brand §7 drop-in) — no new token names, so
   the theme-API contract only *grows* (MINOR: `ThemeApi::VERSION → 1.3.0`). `editableTokens()` stays as
   the legacy 7-token view (now derived from the registry) so nothing breaks.
   The **accent family stays on `accent_color`/`AccentPalette`** (derived hover/soft/ink variants are a
   correctness feature — AA-checked) — U9 does not duplicate accent vars into free-form props.
2. **Dark-layer values** (closes ADR-0037's explicit deferral; required for the Gate-4A dark preset) —
   new nullable `tokens_dark` JSON column; same `cleanTokens()` validation; emission extends
   `tokenCss()` to write the light `:root{}` block plus the two dark blocks (`@media (prefers-color-scheme:
   dark) :root:not([data-theme='light'])` and `:root[data-theme='dark']`) — the exact pattern
   `AccentPalette::for()` already uses.
3. **ACP editor rework** (`⚡themes.blade.php`): grouped sections, light/dark value pairs, server-computed
   **live preview** extended from the existing sample card (adds button/link/status-chip samples) with the
   existing WCAG-AA contrast badges (`AccentPalette::contrastRatio()`).
4. **Migration:** `add_tokens_dark_to_site_themes_table` (nullable json; reversible drop).
5. **Tests:** registry shape + version bump; cleanTokens accept/reject per type incl. injection attempts
   (`;`, `}`, `url(`, unicode); dark emission ordering; ACP save round-trip; preview AA computation.

## U10 — Multi-style tree + per-user chooser (NOV-108, branch `claude/v14-u10-style-tree`, **stacked on U9** — the dark preset needs `tokens_dark`; merge order 4A = U9 → U10)

1. **Tree:** `site_themes.parent_id` (nullable, self-ref) + `is_user_selectable` (bool, default false).
   `StyleThemeManager` (the only writer) enforces: max depth **5**, cycle refusal, **delete refuses while
   children exist**, delete clears any user selections pointing at the row. Reversible migration.
2. **Inheritance resolution** — `effective()` walks child→root: `tokens`/`tokens_dark` merge per-key
   (child wins); `accent_color`, chrome fields, and assets inherit per-field (nearest set value wins);
   `custom_css` concatenates root→child (cascade order = child last, so child wins ties).
3. **Per-user chooser:** `users.style_theme_id` (nullable, plain column per the existing prefs pattern).
   `StyleThemeManager::activeFor(?User)` = user's pick **iff the row exists and `is_user_selectable`**,
   else the single `is_active` default, else null (built-in look). Guests always get the site default
   (per-guest style variance would defeat the server-rendered CSS cache; deliberate non-goal).
   Settings UI: a "Style" fieldset added to `settings/appearance.blade.php` + `AppearanceController`
   (no-JS-safe POST, `Rule::in` against the selectable set + "site default").
4. **Caching:** the three global cache keys become per-theme (`novfora:style-theme:css:{id}` …) folded
   with a **monotonic version key** (`novfora:style-theme:v`); every mutation bumps the version so a
   parent edit atomically invalidates every descendant's compiled CSS — no subtree walking, race-safe on
   the cron-only baseline.
5. **Shipped presets** (Gate 4A): code-defined blueprints in `App\Theme\StylePresets` — root **“NovFora”**
   (brand defaults, not user-selectable) with descendants **“NovFora Light”** and **“NovFora Dark”**
   (user-selectable; Dark deepens the dark layer via `tokens_dark`). Installed idempotently-by-slug from
   the DatabaseSeeder (fresh installs) **and** an ACP "Install presets" action (existing installs). No
   preset is auto-activated — the operator opts in.
6. **Tests:** merge semantics (per-key, chain, depth, cycle), delete guards, per-user resolution incl.
   non-selectable + deleted fallbacks, cache-version busting (parent edit changes child's served CSS),
   settings validation (can't pick a non-selectable id), preset idempotency, **the Gate-4A proof: a child
   theme created entirely through the ACP SFC** (Livewire::test create-with-parent → override two props →
   activate → served CSS shows child override + inherited parent values).

## U11 — Template hooks + Diff3 three-way merge (NOV-109, **ADR-0112**, branch `claude/v14-u11-template-hooks`) ◆ APEX

Two complementary mechanisms, both riding the **existing sandbox unchanged as the render/safety authority**:

1. **Template hook layer** (upgrade-safe by construction): named anchor points in core views —
   `TemplateContract::hooks()` registry (key, label, exposed vars) + `<x-template-hook name="…" :data="…">`
   outlets. Admin-authored fragments live in a new `site_template_hooks` table (`hook_key`, `name`,
   `source`, `position`, `is_enabled`) and render through the SAME sandbox pipeline (parse → lint →
   data-only context → auto-escape → bounded). Initial anchors (~8, additive, MINOR contract bump →
   1.1.0): `site.head.end`, `site.header.after`, `site.footer.before`, `board.header`, `board.footer`,
   `topic.header`, `topic.post.footer`, `profile.header`. Hooks are anchored by NAME, so a core release
   never invalidates them — this is the tool that "replaces whole-file override" for the common cases.
2. **Diff3 merge for full overrides** (the upgrade-survival path for `site_templates`):
   - `site_templates` gains `base_source` (nullable text — the shipped default the admin's edit was based
     on, snapshotted at save) + `merge_state` (`current|merged|conflict`) + `merged_at`.
   - **In-house line-based diff3** (`App\Support\Text\Diff3` — LCS diff + 3-way chunk alignment; bounded:
     ≤2000 lines / ≤64KB per side, over-budget ⇒ treated as conflict, never unbounded work). No new
     dependency (a merge lib would be a stack-changing dep; the algorithm is ~200 lines and clean-room).
   - **`TemplateSync::sync()`**: for every stored override whose shipped default changed
     (hash(default) ≠ hash(base_source)): run diff3(base=base_source, ours=source, theirs=new default).
     Clean merge → **re-lint + re-parse the merged output through the sandbox** (two individually-safe
     inputs can concatenate into a forbidden token — the ADR-0038 split-token lesson; a merged source that
     fails lint is demoted to conflict); on pass, store as `merged`. Conflict → **keep serving the admin's
     existing `source` untouched** (stale but functional — "never fatal"), mark `conflict`, surface in ACP.
     Conflict markers are never written into a live `source`. Wired: `UpgradeRunner::execute()` post-migrate
     (best-effort, caught, audited — mirrors `PermissionSync`), `novfora:templates:sync` artisan command,
     and a lazy staleness check on the ACP templates page.
   - **ACP conflict review** (extends `⚡templates.blade.php`): per-key state badge, side-by-side
     ours/theirs/merged view, resolve actions (keep mine / take new default / accept merge / edit), all
     re-linted on save. Audited.
3. **Gate 4B proof test:** an install with an active style (U9/U10), an edited `topic_footer` override,
   and two hook fragments → simulate a core release (injectable contract defaults in `TemplateSync`) →
   sync → hook mods untouched, clean-merge override updated with the admin's edit intact, a conflicting
   override keeps rendering the old source with the conflict surfaced in the ACP, and **no path throws**.
4. **Apex review lenses** (per kickoff §8): sandbox escape via merged output; template injection through
   hook data contexts; Diff3 corrupting a file (property: merge output is always one of ours/theirs/valid
   interleave — fuzz with random line edits); conflict path fatal-ing an upgrade (UpgradeRunner isolation);
   resource exhaustion (pathological diff inputs); authz on the new ACP writes (admin.access + staff-2FA
   re-assert in every action, the established SFC pattern).

## Sequencing, gates, ADRs

- Build order: **U9 → U10 (stacked) → U11 (independent, off `main`)**. Each slice: full suite +
  pint + phpstan + migrate round-trip green in `forum-dev` (route:clear first) before commit.
- ADR-0112 (U11) lifts into `DECISIONS.md` on the U11 branch. U9/U10 need no ADR (they extend
  ADR-0029/0037 mechanisms within their recorded posture); ThemeApi/TemplateContract version bumps are
  recorded in the class docblocks + this memo.
- New capability keys: none — reuses `admin.access` (+ the settings-section gate) like the existing
  themes/templates pages. New user-facing surface: the Style fieldset on the existing appearance page.
- a11y: the themes + templates ACP pages and the settings appearance page are already in the WCAG gate;
  the reworked editors must keep it green (labels on every new control).
