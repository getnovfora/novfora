<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# Contributing to Hearth

Thanks for your interest in **Hearth** (working codename), an open-source, self-hosted forum platform.
This guide covers how we work and how to get a change merged. Governance, roles, and the decision process
live in [GOVERNANCE.md](GOVERNANCE.md).

> **Project status:** pre-code. Stage A (discovery & architecture) is complete pending the Phase 0 gate; the
> Phase 1 scaffold (and the `.env.example`, seeds, and getting-started below) lands when Phase 1 begins. Until
> then, contributions are to the planning docs under `docs/`.

## Ground rules (the working agreement)

- **Plan before code, per phase.** Each [roadmap](docs/product/roadmap.md) phase is planned and approved before
  implementation. Significant changes go through the **RFC/ADR** process ([DECISIONS.md](DECISIONS.md)).
- **Small, reviewable, single-purpose commits** with **[Conventional Commits](https://www.conventionalcommits.org/)**
  messages (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`…).
- **Tests with every feature** — *no feature is "done" without tests.* The **permission-mask resolution** and
  **service-tier fallback** suites must cover relevant changes ([testing-strategy](docs/architecture/testing-strategy.md)).
- **Keep the baseline tier runnable** at every milestone (PHP 8.3 + MySQL + cron). No baseline feature may
  hard-depend on Redis, a WebSocket server, a worker, or an external search engine — **detect and degrade**.
- **Ask before:** destructive operations, adding a stack-changing dependency, ambiguous product calls, or
  anything that would relitigate a locked decision. State reasonable assumptions inline to keep moving.
- **Strict clean-room:** never copy code/UI/templates/themes/branding/docs from any reference forum
  (commercial **or** open-source). Study concepts/schemas/semantics; reimplement independently. Importers copy
  *data*, never the source program.

## Development setup *(once the Phase 1 scaffold lands)*

```bash
git clone <repo> && cd hearth
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed         # seeds a demo community used by tests + the getting-started guide
php artisan serve                  # baseline-tier dev: file/db cache, db queue, MySQL full-text search
```

Prebuilt assets are committed, so **Node is not required to run**. To rebuild assets for UI work:
`npm install && npm run build` (or `npm run dev`).

> **Committed assets must stay fresh (CI enforces).** `/public/build` is checked into the repo *by design* —
> the baseline shared host has no Node, so it ships prebuilt. Any change that affects the bundle (CSS,
> templates that add/drop utility classes, JS in `resources/js/…`, the Vite/Tailwind config) **must be
> followed by `npm ci && npm run build` and the resulting `public/build` diff committed in the same PR.** The
> CI **`assets-fresh`** guard runs a fresh build and fails on any drift (`git diff --exit-code -- public/build`),
> so stale assets can't be merged and can't reach a git-based deploy. The build is **offline-deterministic**:
> `resources/css/app.css` uses Tailwind's `source(none)` and `@source`s **only** the app's own tracked sources
> (`resources/views` — including our published pagination views under `resources/views/vendor/pagination` — and
> `resources/js`). It does **not** scan `vendor/` or `storage/framework/views`, and the theme uses the
> **system-ui** font stack (no external fonts/CDN). So `npm ci && npm run build` reproduces the committed bundle
> byte-for-byte with **no Composer, no compiled-view state, and no network** — exactly how the Node-only CI
> `assets` job builds it.

## Coding standards

- **Laravel idioms:** Eloquent (parameterized — no raw SQL except where measured and justified), form requests,
  **policies/gates** for authorization, **queued jobs**, **events + listeners** for the extension hook system.
- **Style & static analysis:** **Laravel Pint** (`pint`), **PHPStan/Larastan**. Both run in CI and must pass.
- **Tests:** **Pest/PHPUnit** (unit + feature), **Laravel Dusk** (browser, esp. the WYSIWYG editor). Run:
  `php artisan test` (or `./vendor/bin/pest`), `php artisan dusk`.
- **SPDX header** on every new source file: `// SPDX-License-Identifier: Apache-2.0`.

## Submitting a change

1. Branch from `main`; make your change with tests + docs.
2. Ensure CI passes locally: `pint --test`, `phpstan`, `php artisan test`, relevant `dusk`.
3. **Sign off every commit (DCO):** `git commit -s` adds the required `Signed-off-by:` line. We use the
   **Developer Certificate of Origin**, not a CLA.
4. Open a PR using the template; complete the checklist (tests included, docs updated, conventional title).
5. Review per [GOVERNANCE §3](GOVERNANCE.md): ≥1 Maintainer approval (≥2 for security/public-API/migration/
   permission/anti-spam changes). A Maintainer merges — authors don't merge their own PRs.

## Where things live

- `docs/research/`, `docs/architecture/`, `docs/product/` — the Stage A design set.
- [`ARCHITECTURE.md`](ARCHITECTURE.md) · [`DECISIONS.md`](DECISIONS.md) (ADRs) · [`ROADMAP.md`](ROADMAP.md) —
  living docs.
- [`GOVERNANCE.md`](GOVERNANCE.md) · [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) · [`LICENSE`](LICENSE).

## License

By contributing, you agree your contributions are licensed under the **Apache License 2.0** ([LICENSE](LICENSE)).
