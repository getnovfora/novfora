<!-- SPDX-License-Identifier: Apache-2.0 -->
# Sandbox template engine — threat model (Theme Studio 1.6 / ADR-0038)

> **Status: flagged for dedicated human security review.** Built in the owner-authorized overnight run with
> an adversarial test battery (`tests/Feature/Theme/SandboxTemplateTest.php`). This document is the threat
> model a reviewer should work against.

## What it is

A **restricted, logic-light template language** (Option A) that lets an admin customise designated parts of
the UI without filesystem access or PHP. Syntax: literal author HTML, `{{ expression }}` output, `{% if %}` /
`{% for %}` control, dotted variable paths, and a fixed set of pure helpers. It is **not** Blade, Twig, or
`eval` — it is a bespoke lexer → parser → evaluator under `app/Theme/Sandbox/`.

## Trust model

Templates are authored by **admins** (`admin.access` + staff-2FA, re-asserted on every Livewire action). Admins
already have more power than this sandbox (custom CSS, and filesystem child themes that override raw Blade). So
the sandbox is **defence-in-depth + future-proofing**: it gives admins a *safe* managed-editing surface, and is
designed so the same engine could later be exposed to *lower-trust* theme authors. The hard guarantee is: **a
template can never execute code, reach a model/service, or run an un-whitelisted function** — regardless of who
writes it.

## Safety invariants (the load-bearing design)

1. **The context is data-only.** Every value handed to the renderer is a scalar or array (`TemplateService`
   builds it from models into plain arrays). Variable resolution (`SandboxRenderer::resolvePath`) does
   **array-key access only**; a non-array level or missing key yields `null`. There is no syntax — and no code
   path — that reads an object property or calls a method, even if an object accidentally leaks into the context.
2. **Calls are whitelist-only.** `{{ name(args) }}` resolves `name` against `SandboxRenderer::helpers()` (a
   fixed map of pure string/array functions). An unknown name throws. There is no way to reference a PHP
   function, a closure, or `app()`/`config()`.
3. **The tokenizer is an allowlist.** `SandboxExpression` accepts only letters, digits, `_ . ' " ( ) ,` and the
   six comparison operators. Any other byte (`$ ; : [ ] { } \ + - * / % & | ^ ~ @ # backtick`, and thus `::`
   `->` `[]` arithmetic) is a hard parse error.
4. **Dynamic output is auto-escaped.** Every `{{ }}` value is HTML-escaped with Laravel `e()` (double-encode
   on). There is no raw-output construct.
5. **Bounded.** Source ≤ 50 000 chars; template nesting ≤ 24; nodes ≤ 4 000; expression nesting ≤ 100 (guards a
   parse-time stack overflow); ≤ 5 000 total loop iterations; ≤ 200 000 output bytes. Breaching any limit throws
   — a render can't hang or OOM. A `for` over a non-array is a no-op (no string/char iteration).
6. **Fail-safe.** A parse/runtime error throws `SandboxException`; `TemplateService::render()` catches it and
   returns `''`, so a broken or hostile template degrades to nothing rather than breaking the page or leaking a
   stack trace. A template renders **only** when an admin has explicitly enabled it.
7. **Save-time lint (defence-in-depth).** Before storage, `TemplateService::lint()` requires the source to
   parse, then scans the **literal skeleton** — the source with every `{{…}}`/`{%…%}` tag removed — for
   `<script>/<style>/<iframe>/<object>/<embed>/<base>/<meta>/<link>`, inline `on…=` handlers, and `javascript:`.
   Scanning the *skeleton* (not the raw source) is deliberate: dynamic `{{ }}` output is escaped, so only
   literal text can introduce raw markup, and stripping the tags collapses a token split across a tag
   (`<scr{{ x }}ipt>`) so it is caught. This sits on top of (4) and readies the engine for lower-trust authors.

> **Adversarial-review finding (FOUND → FIXED in this build).** The first cut of the lint scanned the *raw
> source* with `stripos`, so `<scr{{ x }}ipt>…</scr{{ x }}ipt>` passed the lint yet rendered live `<script>`
> (a HIGH stored-XSS, executable under the default permissive CSP). Fixed by scanning the literal skeleton, as
> above; the 4 PoCs are now in the adversarial battery as must-block cases. The CORE guarantees (1–6) were
> verified safe by the same review with no escape.

## Attack surface → mitigation

| Vector | Mitigation |
|---|---|
| Execute PHP / call `system`/`eval`/`app()` | No `eval`/Blade; calls are whitelist-only (invariant 2); sigils/`::`/`->` rejected by the tokenizer (3). |
| Reach a model / service / container | Context is data-only; resolution is array-key access; objects are never traversed (1). |
| Stored XSS via a data value in text or a quoted attribute | `{{ }}` is `e()`-escaped (4). Data carrying `{{ }}` is **not** re-parsed (no double-render). |
| Inject `<script>` via literal template text | Save-lint rejects it (7); and even so, the engine can't execute. |
| DoS — infinite loop / huge output / deep nesting / stack overflow | Iteration, output, source, node, template-depth and expression-depth caps (5). |
| Parser confusion (smuggled tags, `}}` in strings, call-on-result) | Unbalanced/unknown tags and trailing tokens throw; `}}` inside a string fails *closed* (parse error), never an escape. |

## Residual risks (documented, for the reviewer)

- **Unquoted-attribute / CSS-context interpolation** — `e()` is correct for **text and quoted attributes**, the
  same contract as Blade's `{{ }}`. A template author who writes a dynamic value into an **unquoted** attribute
  (`<a href={{ x }}>`) or inside a `style="…"`/`url(…)` with user-derived data could still create an injection,
  exactly as in Blade. The guidance (and the editor copy) is: put dynamic values in **quoted attributes or text**.
  A future hardening could add context-aware escaping or a structural output sanitiser; out of scope for 1.6.
- **Admin trust** — an admin can still write a confusing-but-inert template (e.g. misleading text). This is a
  content-moderation concern, not a code-execution one.
- **Helper evolution** — every new helper MUST be pure (no I/O, no reflection, no side effects). Adding one is a
  MINOR contract bump (`TemplateContract::VERSION`); review each addition against this rule.

## What the human reviewer should do

1. Try to break invariants 1–4 with new PoC templates; extend the adversarial battery.
2. Decide whether the unquoted-attribute residual is acceptable for your exposure, or whether to add an output
   sanitiser before exposing the engine to non-admin authors.
3. Confirm the helper set stays pure as it grows.
4. **Consider enabling strict nonce CSP by default** (`NOVFORA_CSP_STRICT`). The baseline ships
   `script-src 'self' 'unsafe-inline'`, so an injected inline `<script>` would execute. The skeleton-lint +
   output escaping prevent injection today, but strict CSP is the belt to that braces — strongly recommended
   before delegating template authoring beyond full admins.
