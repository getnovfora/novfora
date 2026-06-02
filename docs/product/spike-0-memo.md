# Spike 0 memo — Editor ↔ Livewire 4   (GO/NO-GO)

> **Decision: GO** — the `wire:ignore` + Alpine-island pattern (canonical TipTap JSON synced via
> `$wire.set`, HTML rendered + sanitized server-side) is validated as the editor↔Livewire-4 mechanism.
> **Date:** 2026-06-02. **Run env:** Docker `php:8.3-cli` (`.spike-docker/Dockerfile`) for PHP/Composer/Pest;
> host Node 24 for Vite; Playwright 1.60 + Chromium driving the live app. Scaffold in `hearth-spike/`.
> **No fallback (§5) was needed.** ADR-0012 stands as written.

## Resolved versions (all MIT unless noted)

| Component | Version | Notes |
|---|---|---|
| Laravel | **13.12.0** | GA; PHP 8.3 floor |
| Livewire | **4.3.0** | single-file components (see findings) |
| PHP | **8.3.31** | container |
| TipTap (`@tiptap/*`) | **3.24.0** | core, pm, starter-kit, placeholder, mention, suggestion, image — all **MIT** |
| symfony/html-sanitizer | **7.4.13** | MIT — the security boundary |
| Node / Playwright | 24.15 / 1.60 | host-side build + browser tests |

`composer audit` clean; every npm dep MIT (ADR-0015 satisfied). No `@tiptap-pro/*`.

## Criteria results — all six PASS (executed, not asserted on paper)

| # | Criterion | Result | How validated |
|---|---|:--:|---|
| **1a** | Editor state survives a Livewire re-render (**GO-blocker**) | ✅ PASS | Playwright: content intact after a **sibling re-render** *and* a **validation-error** re-render |
| 1b | `wire:navigate` cursor restoration | ⚪ n/a | best-effort/documented per handoff; not a blocker, not separately tested |
| **2** | Drag-drop / paste image upload | ✅ PASS | Upload endpoint returns 200; image node inserted. Drop/paste/picker share one `uploadAndInsert`; test drives the picker (synthetic native file-drop is unreliable headless) |
| **3** | `@mentions` (and slash via same suggestion util) | ✅ PASS | Playwright: typing `@al` → list → select → `.mention` node inserted |
| **4** | Canonical JSON → sanitized HTML, **lossless + XSS-safe** | ✅ PASS | **Pest: 8 tests / 80 assertions** — XSS battery (script/onerror/onload/iframe/svg/style, `javascript:`/`data:` links) all neutralized; safe links get `rel="nofollow noopener noreferrer"`; multibyte/RTL round-trips. Confirmed end-to-end in-browser (save → preview) |
| **5** | Keyboard-operable + ARIA | ✅ PASS | Playwright: `role=textbox`, `aria-multiline`, `contenteditable`; keyboard-only typing works |
| **6** | Editor JS ≤ ~180 KB gz, code-split, lazy | ✅ PASS | Vite: main bundle **0.87 KB gz** (TipTap not in it); editor lazy chunk **~114 KB gz** |

Full PHP suite: **10 passed (82 assertions)**. Full browser suite: **6 passed**.

## The validated pattern (and where it lives)

The editor is a TipTap instance mounted inside a **`wire:ignore`** Alpine island; it emits **canonical TipTap
JSON only** (never HTML), synced to the Livewire component via a **deferred `$wire.set`**; on save the server
turns canonical JSON into **sanitized HTML** via `CanonicalRenderer`. Reference files (committed under
`hearth-spike/`, heavy artifacts git-ignored):

- `app/Support/CanonicalRenderer.php` — **the security boundary** (JSON→HTML mapper with per-value escaping + an
  allowlist sanitizer backstop). `tests/Unit/CanonicalRendererTest.php` proves criterion #4.
- `resources/js/editor/hearth-editor.js` — TipTap factory (StarterKit + Placeholder + Mention + Image; paste/drop
  upload handlers).
- `resources/js/editor/island.js` — the Alpine island (closure-state editor; deferred sync). **Read the header
  note here — it encodes the #1 finding below.**
- `resources/views/components/⚡post-composer.blade.php` — the Livewire 4 **single-file** component.
- `routes/web.php` (`/spike`, `/spike/upload`), `resources/views/spike.blade.php`, `e2e/editor.spec.js`.

## Findings — corrections/constraints the spike surfaced (carry into M2)

1. **Livewire 4 uses single-file components.** `make:livewire` produced a `⚡`-prefixed file in
   `resources/views/components/` with `new class extends Component {…}` + Blade in one file — **not** a class in
   `app/Livewire/`. Method injection (`save(CanonicalRenderer $r)`) and `$this->validate()` work as expected.
2. **⭐ Keep the editor OUT of the reactive layer.** Storing the TipTap editor as a reactive Alpine property
   (`this.editor`) makes Alpine proxy ProseMirror's state → programmatic commands throw **"Applying a mismatched
   transaction."** (Typing still works — ProseMirror's own DOM handlers hold a raw reference.) **Fix:** keep the
   editor in **per-instance closure state** (the island does this). This is the single most important M2 rule for
   the editor and any other self-managing JS widget embedded in Livewire.
3. **Deferred sync needs no debounce.** `$wire.set('canonicalJson', json, false)` is **JS-only (no network)**, so
   sync on every change; debouncing it caused a **stale doc on an immediate save**. (Debounce only the *network*
   autosave/draft, later.)
4. **Async insert must defer + use `insertContent`.** Inserting synchronously right after `await` (the upload
   fetch) also triggers the mismatched-transaction error; defer one tick then
   `editor.commands.insertContent({ type:'image', … })`.
5. **StarterKit v3 bundles Link** (and more) — do **not** re-register it. Placeholder/Mention/Image are separate.
6. **The sanitizer is strict (good).** symfony/html-sanitizer entity-encodes `@`→`&#64;` and `=`→`&#61;` in
   attribute values (harmless; renders correctly) and drops `script`/event-handlers/`javascript:`/`data:`. The
   allowlist is the authoritative safe surface; the JSON→HTML mapper escapes as defense-in-depth.
7. **Drag-drop & paste** are wired via `editorProps.handleDrop`/`handlePaste`, both calling the same
   `uploadAndInsert`; only the upload→insert pipeline is automated (via the file picker) because synthetic native
   file-drops are unreliable in headless browsers — a test-harness limitation, not an integration gap.

## Reproduce

```bash
docker build -t hearth-spike-env -f .spike-docker/Dockerfile .spike-docker
# PHP/Pest (in container, bind-mounted):  php artisan test
# JS build + bundle sizes (host):          npm run build   (in hearth-spike/)
# Browser criteria (host, app served on :8000): playwright test -c hearth-spike/playwright.config.js
```

## Next (per the plan — owner gate)

GO confirms the editor mechanism. **The pattern now folds into Phase 1 M0→M5** (port `CanonicalRenderer`, the
island closure pattern, and the SFC composer into the real app at the repo root), per
[phase-1-plan.md](phase-1-plan.md). No change to ADR-0012; findings #1–#7 above become M2 implementation notes.
The `hearth-spike/` scaffold stays as a reference until M0 supersedes it.
