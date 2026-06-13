<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Phase 3 — Importers (B4)

> A clean-room, driver-based legacy importer: phpBB built + tested, MyBB/SMF scaffolded. Idempotent, resumable,
> SEO-preserving. **Status: Accepted — owner-authorized overnight build; flagged for review (ADR-0034).**

## 1. Shape

```
SourceDriver (interface)  ── reads a legacy DB READ-ONLY, maps rows to a source-agnostic vocabulary
  ├─ PhpbbDriver   (verified — the highest-value source; + attachments/checksum)
  ├─ MybbDriver    (verified against a fixture suite; + attachments/checksum)
  └─ SmfDriver     (verified against a fixture suite; title-from-first-message; + attachments/checksum)
ProvidesAttachments       ── optional driver capability: import attachment files + verify by sha-256 checksum
ImportRunner              ── driver-agnostic: preflight → import → verify; idempotent + resumable
import_maps               ── (source, kind, source_id) → target_id, UNIQUE: the idempotency + resume ledger
redirects                 ── 301 maps, served by the LegacyRedirectController route fallback
BbcodeConverter           ── clean-room BBCode → canonical markdown
novfora:import            ── the CLI entry (preflight | import)
```

**Clean-room** (the hard rule): a driver encodes only the reference forum's **public DB schema** (table +
column names) to copy DATA. It never reads or adapts that forum's code or templates — and that holds for SMF
too, even though its BSD licence would technically allow code reuse.

## 2. The three stages (the C7 answer)

1. **Preflight** — read-only: connectivity check (abort if the source is unreachable), count users / forums /
   topics / posts, and report what's already imported.
2. **Import** — batched, keyset-cursored work. Every created entity is recorded in `import_maps`, so the run is
   **idempotent** (a re-run skips what exists) and **resumable** (it continues from the last id). A
   multi-million-row board survives an interruption and fits cron windows on the baseline tier.
3. **Verify** — reconcile per kind, AND beyond counts: a sample of imported post bodies is compared to the
   source-derived canonical, and every imported attachment's stored file is re-hashed against its recorded
   sha-256 (CONTENT + checksum verification, not just row counts).

Imports go **straight through the Eloquent models, not the post/topic services**, so a bulk import fires **no
domain events** — no webhook storm, no activity-feed flood, no reputation awards.

## 3. Fidelity

- **Hierarchy + authorship** preserved via the `import_maps` (a child forum resolves its parent's new id; a
  topic/post resolves its author + forum). Bots (phpBB `user_type = 2`) are excluded.
- **Content** — `BbcodeConverter` maps BBCode → markdown (stripping phpBB's per-post `bbcode_uid`); the post
  renders + sanitises through the normal pipeline.
- **Passwords** — the legacy hash is stored via the `hashed` cast: a valid bcrypt (`$2y$`) hash verifies and
  auto-rehashes to argon2id on first login; anything unverifiable simply fails the check, so that user resets.
  No forced reset for modern hashes. (MyBB/SMF hash schemes aren't Laravel-verifiable → those users reset.)
- **SEO** — legacy URLs (e.g. `/viewtopic.php?t=5`) become 301 `redirects`, served by the route **fallback**
  so the table is only consulted for an otherwise-unmatched URL.
- **Attachments** — a driver implementing `ProvidesAttachments` (phpBB/MyBB/SMF do) exposes each legacy
  attachment's bytes; the runner stores them on the app disk, records a sha-256 checksum (the same column the
  native uploader writes), links them to the imported post, and the verify pass re-hashes to confirm integrity.

## 4. Running it

```
# configure a read-only "legacy" DB connection in config/database.php, then:
php artisan novfora:import phpbb --connection=legacy --prefix=phpbb_ --preflight   # count + plan
php artisan novfora:import phpbb --connection=legacy --prefix=phpbb_               # import (re-runnable)
```

## 5. Follow-ups (flagged)

- Verify the MyBB and SMF drivers against a LIVE board. They are now verified here against representative
  fixtures (full import + attachments + idempotency/resume), but a live board can surface schema-version
  quirks — phpBB remains the highest-confidence path.
- Richer BBCode coverage (tables, nested quotes) and oEmbed re-resolution.
