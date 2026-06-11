# Spike 0 — Build & Validation Handoff (Editor ↔ Livewire 4)

> **Purpose:** a deterministic, copy-pasteable handoff so a **Claude Code build session** (with PHP 8.3 +
> Composer + Node) can execute **Spike 0** — the first action of [phase-1-plan.md](phase-1-plan.md) §4.
> This planning/Cowork session has no PHP toolchain, so the build runs where PHP lives.
> **Goal:** validate the **`wire:ignore` + Alpine island** pattern (canonical JSON synced explicitly via
> `$wire.set`) as the TipTap↔Livewire 4 integration mechanism against **6 GO criteria**, and emit a
> **GO/NO-GO memo** + a reference pattern that M2 builds on.
> **No production code yet** — this is a throwaway spike; keep it in a scratch dir until GO.

> ⚠️ **Version caveat (read first):** Livewire 4 is new (v4.3, May 2026). **Two distinct LW4 features — keep them straight:** `wire:ignore`
> excludes an element's DOM from Livewire's morph (this is what protects TipTap's nodes); **"islands"** are a
> separate partial-re-render optimization. The editor's isolation boundary is **`wire:ignore`**, *not* islands
> (islands are at most a complementary way to scope the composer's re-renders). The exact **sync API** below
> is the *intended* mechanism — **verify it against the current Livewire 4 docs** in the build env and adjust
> syntax as needed. The underlying pattern is robust regardless of syntax: **the editor owns its own DOM
> (isolated from Livewire's morph via `wire:ignore`), canonical JSON is synced explicitly via `$wire.set`, and HTML is always (re)rendered +
> sanitized server-side.** If `wire:ignore` underperforms, fall back per §5 — that's the whole point of the spike.

---

## 0. Prerequisites (confirm in the build env)

```bash
php -v          # expect 8.3+ (8.4 fine) — Laravel 13 requires 8.3 (GA 2026-03-17)
composer -V
node -v         # 20+ (22/24 fine)
npm -v
```
A DB server is **not** required for the spike — **SQLite** is enough. (The real Phase 1 app uses MySQL.)
**Dusk** needs a headless Chrome/Chromium + matching ChromeDriver present (`dusk:install` fetches the driver,
but the browser binary must exist). **Time-box:** this is throwaway — if `wire:ignore` + Alpine island isn't
clearly GO within ~1 focused day, record the failing criteria and drop to the §5 fallback rather than polishing.

---

## 1. Scaffold (deterministic)

Do the spike in a **scratch directory** (not the repo root) so `D:\Forum` stays clean until GO:

```bash
# 1. Laravel 13 + Livewire 4
composer create-project "laravel/laravel:^13" nevo-spike
cd nevo-spike
composer require "livewire/livewire:^4.0"
composer require symfony/html-sanitizer      # MIT — server-side allowlist sanitizer

# 2. SQLite (spike DB)
#    .env: set DB_CONNECTION=sqlite and remove the other DB_* lines
touch database/database.sqlite
php artisan migrate

# 3. TipTap (MIT core/extensions ONLY — never @tiptap-pro/*)
#    Pin the TipTap major version (e.g. ^3). Check what StarterKit already bundles BEFORE adding extensions —
#    in TipTap 3 StarterKit includes Link and others; registering a bundled extension a second time throws.
npm install
npm install @tiptap/core @tiptap/pm @tiptap/starter-kit \
            @tiptap/extension-placeholder @tiptap/extension-mention @tiptap/suggestion
# Alpine ships with Livewire; if building standalone JS, also: npm install alpinejs

# 4. Dusk for the automated criteria
composer require --dev laravel/dusk
php artisan dusk:install
```

**License check (ADR-0015):** confirm every npm dep resolves to **MIT/BSD/Apache** (`npm ls --all` +
spot-check). `@tiptap/*` core/extensions are MIT; **do not** add `@tiptap-pro/*` or the collaboration server.
`symfony/html-sanitizer` is MIT. Record anything non-obvious in `DECISIONS.md`.

---

## 2. Reference implementation (the intended `wire:ignore` + Alpine island pattern)

> These are **starting-point references**, not verified code — run + adjust in the build env. They encode the
> *contract*: TipTap owns the DOM inside a Livewire-ignored boundary; canonical JSON flows to the server;
> server renders + sanitizes HTML.

### 2a. Editor factory — `resources/js/editor/novfora-editor.js`
```js
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import Placeholder from '@tiptap/extension-placeholder'
import Mention from '@tiptap/extension-mention'
// Slash commands: build on @tiptap/suggestion (see TipTap docs); omitted here for brevity.

// Factory returns a TipTap editor; caller wires persistence + events.
export function createNovForaEditor({ element, content, onUpdate }) {
  return new Editor({
    element,
    extensions: [
      StarterKit,                          // bold/italic/lists/headings/code/quote — MIT
      Placeholder.configure({ placeholder: 'Write something…' }),
      Mention.configure({ /* suggestion: …mention source… */ }),
      // TODO(build env): add slash-command (suggestion), table, code-block, spoiler nodes for the richer set.
    ],
    content: content ?? { type: 'doc', content: [] },   // canonical TipTap JSON in/out
    onUpdate: ({ editor }) => onUpdate(editor.getJSON()), // emit canonical JSON, never HTML
  })
}
```

### 2b. Alpine island — registered for the `wire:ignore` mount
```js
// resources/js/editor/island.js  (registered from app.js; the heavy TipTap code is dynamically
// imported below so it is code-split OUT of the main bundle — see criterion #6)
document.addEventListener('alpine:init', () => {
  window.Alpine.data('nevoEditor', (initialJson) => ({
    editor: null,
    async init() {
      // Lazy-load the factory so @tiptap/* lands in its own chunk, not app.js:
      const { createNovForaEditor } = await import('./novfora-editor')
      this.editor = createNovForaEditor({
        element: this.$refs.mount,
        content: initialJson,
        // Debounced sync of CANONICAL JSON to the Livewire component (no DOM coupling):
        onUpdate: Alpine.debounce((json) => this.$wire.set('canonicalJson', json, false), 400),
      })
    },
    destroy() { this.editor?.destroy() },
  }))
})
```
> **Budget (criterion #6):** the `import()` above is what keeps `@tiptap/*` off the critical path. If you
> `import` the factory statically at the top of `app.js` instead, the editor ships in the main bundle and
> **#6 fails by construction.** Measure the lazily-loaded editor chunk's gzipped size in isolation.

### 2c. Livewire 4 component — `app/Livewire/PostComposer.php`
```php
<?php
// SPDX-License-Identifier: Apache-2.0
namespace App\Livewire;

use Livewire\Component;
use App\Support\CanonicalRenderer;   // JSON -> sanitized HTML (server-side, the security boundary)

class PostComposer extends Component
{
    public array $canonicalJson = ['type' => 'doc', 'content' => []]; // canonical source of truth
    public string $previewHtml = '';

    public function save(CanonicalRenderer $renderer)
    {
        // HTML is ALWAYS generated server-side from canonical; the browser never supplies HTML.
        $this->previewHtml = $renderer->toSafeHtml($this->canonicalJson);
        // …persist canonical + html cache + text projection (ADR-0005) in the real app…
        $this->validate(['canonicalJson' => ['required', 'array']]);
    }

    public function render() { return view('livewire.post-composer'); }
}
```

### 2d. Blade view — `resources/views/livewire/post-composer.blade.php`
```blade
{{-- Editor mounts inside a `wire:ignore` boundary so Livewire never morphs TipTap's DOM. VERIFY directive/syntax against current Livewire 4 docs. --}}
{{-- The non-negotiable is wire:ignore around the editor DOM so Livewire never morphs TipTap's nodes. --}}
<div>
  <div
    wire:ignore
    x-data="nevoEditor(@js($canonicalJson))"
    x-ref="mount"
    class="prose max-w-none border rounded p-3 min-h-[12rem]"
    aria-label="Post editor"
    role="textbox"
    aria-multiline="true"
  ></div>

  <button wire:click="save" class="mt-2 …">Preview / Save</button>

  @if ($previewHtml)
    <div class="mt-4 prose" aria-label="Rendered preview">{!! $previewHtml !!}</div>
  @endif
</div>
```

### 2e. Server-side canonical → safe HTML — `app/Support/CanonicalRenderer.php`
```php
<?php
// SPDX-License-Identifier: Apache-2.0
namespace App\Support;

use Symfony\Component\HtmlSanitizer\{HtmlSanitizer, HtmlSanitizerConfig};

class CanonicalRenderer
{
    // Spike scope: a minimal TipTap-doc -> HTML mapper, then an allowlist sanitizer.
    public function toSafeHtml(array $doc): string
    {
        $html = $this->nodesToHtml($doc['content'] ?? []);   // implement per supported node set
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()                            // allowlist; drops script/onerror/etc.
            ->allowElement('a', ['href'])->forceAttribute('a', 'rel', 'nofollow noopener');
        return (new HtmlSanitizer($config))->sanitize($html);
    }

    private function nodesToHtml(array $nodes): string { /* paragraph/heading/list/code/… */ return ''; }
}
```
> The **sanitizer is the security boundary** (criterion #4). Even if the client sends crafted JSON, the
> server emits only allowlisted HTML. Never trust client HTML; never store unsanitized HTML.
>
> **Required spike work (not optional):** `nodesToHtml()` ships as an empty stub above, but **criterion #4
> cannot be evaluated until it is implemented** for a defined node set. For the spike, implement + sanitize
> exactly: **paragraph · heading (h1–h3) · bold · italic · bullet/ordered list · blockquote · code block ·
> link · mention.** (Tables/spoilers/slash-inserted nodes are M2, not the spike.) This renderer is the bulk
> of the spike — it *is* the security boundary, so budget time for it.

---

## 3. The six GO criteria — how to validate each (acceptance gate)

| # | Criterion | How to test (Dusk unless noted) | Pass condition |
|---|---|---|---|
| 1a | **State survival (core)** | Dusk: type content; trigger a Livewire validation error **and** a sibling-component update; re-inspect | Content **and** cursor/selection preserved; zero loss — **GO-blocker** |
| 1b | **State survival (`wire:navigate`)** | Dusk: type content; `wire:navigate` away and back | Content preserved; cursor restoration is **best-effort** (may need `@persist`). **Document, don't block** — drafts/autosave is Phase 2 |
| 2 | **Uploads** | Dusk/manual: drag-drop **and** paste an image to a stub route `POST /spike/upload` (accept `image/png,jpeg,gif,webp`, ≤5 MB) returning `{url}` | File uploads, server validates type/size, node inserted; progress shown |
| 3 | **Mentions + slash** | Dusk/manual: type `@` and `/` | Suggestion menus open and insert correctly inside the island |
| 4 | **Round-trip safety** | Pest unit: feed canonical JSON containing the XSS payloads below to `CanonicalRenderer`; also JSON→HTML→re-parse | Output has **no** `<script>`/`onerror`/`javascript:`; **lossless = normalized deep-equal** of TipTap JSON before vs. after re-parse, for supported nodes |
| 5 | **A11y + mobile** | Keyboard-only run (Tab/focus/shortcuts) + `axe` + 375px viewport | Fully keyboard-operable, visible focus, ARIA present; usable on touch |
| 6 | **Budget** | `npm run build`; inspect the editor chunk (gzipped) | Editor island **≤ ~180 KB gz**, **lazy-loaded**, **not** in the main bundle; no input jank |

**XSS payload battery for #4** (assert all are neutralized):
```
<script>alert(1)</script> · <img src=x onerror=alert(1)> · <a href="javascript:alert(1)">x</a> ·
<svg/onload=alert(1)> · <iframe src=javascript:alert(1)> · style="expression(alert(1))" ·
a TipTap "link" node whose href is javascript: · nested/broken markup
```

---

## 4. GO/NO-GO memo (fill this and commit it as `spike-0-memo.md`)

```
# Spike 0 memo — Editor ↔ Livewire 4   (date, commit)
Mechanism tested: wire:ignore + Alpine island ($wire.set JSON sync)
Versions: Laravel ____ | Livewire ____ | TipTap ____ | symfony/html-sanitizer ____
1a State survival (core) ..... PASS/FAIL     notes:
1b State survival (navigate) . PASS/FAIL/N/A  notes:
2 Uploads .............. PASS/FAIL  notes:
3 Mentions + slash ..... PASS/FAIL  notes:
4 Round-trip safety .... PASS/FAIL  notes:
5 A11y + mobile ........ PASS/FAIL  notes:
6 Budget (≤180KB gz) ... PASS/FAIL  measured: __ KB
DECISION: GO (wire:ignore + Alpine island)  |  NO-GO → fallback chosen: ____  because: ____
Reference pattern committed at: ____    Constraints it imposes on M2: ____
```
**Rule:** no editor work in M2 starts until this memo says **GO** on a specific mechanism. **1a is a GO-blocker;
1b is informational** (a 1b FAIL is recorded as an M2 constraint, not a NO-GO).

---

## 5. Fallback chain (from [phase-1-plan.md](phase-1-plan.md) §4) — if `wire:ignore` + Alpine island is NO-GO

1. **Livewire 4 JS-component bridge** — wrap TipTap in a dedicated **Vue/React** component via the bridge;
   sync canonical JSON through its prop/event channel. **Confirm this bridge exists as a first-class LW4
   mechanism before relying on it** — if not, skip to #2/#3 (the real safety net).
2. **Decoupled Alpine island** fully outside Livewire's DOM; sync canonical JSON to a hidden input read on submit.
3. **Standalone JS editor** posting canonical JSON to a dedicated endpoint.

All three preserve the **canonical-storage + server-sanitize** boundary. Pick the highest one that passes all 6.

---

## 6. After GO

1. Commit the spike memo + the validated reference pattern.
2. Begin **M0** (real app scaffold at `D:\Forum`) and port the validated editor pattern into it.
3. Proceed M1→M5 per [phase-1-plan.md](phase-1-plan.md), each milestone runnable + tested on the baseline tier.

---

## 7. What to report back to this planning session

The filled **GO/NO-GO memo** (or just: mechanism + 6 results + decision). On GO, I'll fold the confirmed
pattern into the M0→M5 build plan and the architecture docs. On NO-GO, I'll update [phase-1-plan.md](phase-1-plan.md)
§4 and ADR-0012 with the chosen fallback before M2 proceeds.
