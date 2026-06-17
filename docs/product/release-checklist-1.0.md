<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora 1.0.0 — Release checklist

> The gated, repeatable steps to cut a `v1.0.0` release. Everything in **Pre-flight** is automated/verifiable
> in this repo; **Cut** is the owner's tag+build; **Go-live validation** is the set of scaffolded-but-unproven
> integrations that need a real service before they can be relied on (carried from Phase 4 + Phase 5). See
> `CHANGELOG.md` and `PROJECT-STATE.md → VALIDATE-BEFORE-GO-LIVE`.

## Pre-flight (must be green before tagging)

- [ ] **Gates green on the release commit:** `php artisan migrate` · `composer test` (Pest) ·
      `vendor/bin/pint --test` · `vendor/bin/phpstan analyse` · `composer audit --no-dev` · `npm audit`.
- [ ] **Brand gate:** `git grep -il nevo -- . ':(exclude)docs/' ':(exclude)*.md'` returns nothing outside
      historical ADR/doc references (enforced by the CI `static` job's "Brand gate" step).
- [ ] **Version is `1.0.0`:** `config/app.php` `version` (or `APP_VERSION`) = `1.0.0`; `GET /health` reports it.
- [ ] **CHANGELOG.md** has the `1.0.0` entry and the date is set.
- [ ] **Assets are prebuilt + committed:** `npm run build` produced `public/build/manifest.json` + hashed
      assets with no stale files (the host needs no Node runtime).
- [ ] **Docs current:** `README.md`, `docs/getting-started.md` (install + upgrade), `CONTRIBUTING.md`,
      `GOVERNANCE.md`, `CODE_OF_CONDUCT.md`, `LICENSE` (Apache-2.0 + SPDX headers).
- [ ] **Fresh-install smoke passes:** `tests/Feature/Install/FreshInstallSmokeTest.php` (drives the wizard on
      an empty DB: schema + roles/permissions/system groups seeded + first admin created + lock written).

## Cut the release

- [ ] **Build the installable artifact:** `bash scripts/build-release.sh` → `novfora-release.zip`.
- [ ] **Verify the artifact:** `bash scripts/verify-release.sh novfora-release.zip` (truly-cold HTTP boot →
      `/` 302 `/install`; ships `bootstrap/cache/packages.php`; ships NO `.env` / install marker / env caches).
- [ ] **Tag:** `git tag -s v1.0.0 -m "NovFora 1.0.0"` and push the tag.
- [ ] **Publish** the GitHub release with `CHANGELOG.md`'s 1.0.0 notes + attach `novfora-release.zip` +
      its sha256.

## Go-live validation (scaffolded — prove against a REAL service before relying on)

These ship inert/disabled by default and are unit-tested against fakes/mocks only. Enable + validate per the
named ADR / `PROJECT-STATE.md` step:

- [ ] **Meilisearch** (ADR-0060) — set `SCOUT_DRIVER`/`MEILISEARCH_*`, `scout:import`, confirm a private-club
      post never surfaces for a non-member.
- [ ] **Reverb realtime** (ADR-0061/0062) — install reverb + echo, run the server, confirm the websocket
      round-trip + that the channel-authz no-leak holds live.
- [ ] **Live Stripe** (ADR-0065 + P5.1) — real keys + webhook secret; a test-mode checkout grants only on
      `payment_status=paid`; add `invoice.*` / subscription-cancelled handling before auto-renewal.
- [ ] **OAuth / SAML** (ADR-0053–0056) — real apps + redirect URIs; confirm the no-merge rule + the staff-2FA
      step-up (P5.1) end to end.
- [ ] **Web Push** (ADR-0058) — VAPID keys; confirm encrypt-and-POST to a live push service.
- [ ] **StopForumSpam submission** (ADR-0069) — optional; key + the content-privacy opt-in.
- [ ] **Load test** (ADR-0045/0072) — run `novfora:loadtest:seed` + k6/artillery on the real baseline AND
      enhanced host; capture p50/p95/p99 vs the suggested SLOs; EXPLAIN the forum-listing sort at scale.
- [ ] **Accessibility** (ADR-0044) — the manual checklist (contrast/keyboard/focus/SR/RTL/reduced-motion) on
      the key flows.

## Post-release

- [ ] Open the milestone for the next cycle; move any deferred fast-follows (e.g. sole-owner club-ownership
      transfer-before-deletion, ADR-0047) into it.
- [ ] Announce; point the community at the module/theme contribution guide and the i18n locale-contribution
      path (other locales are community-maintained).
