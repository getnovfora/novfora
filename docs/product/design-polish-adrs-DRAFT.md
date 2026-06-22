<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# DRAFT ADRs — Design-Polish Program (lift into `DECISIONS.md` on acceptance)

> **Status: DRAFT / Proposed — awaiting owner approval.** These are written in the `DECISIONS.md` house format
> (one summary table row + one concise detail block each) so they can be pasted in verbatim once accepted.
> **Confirm the numbers are next-free before lifting** — PROJECT-STATE last referenced **ADR-0091**, so 0092/0093
> are expected free, but apply the RH-4 "don't collide" check against the live `DECISIONS.md` table first.

---

## Table rows (paste into the `DECISIONS.md` index table)

```
| 0092 | **Design-Polish Program (post-1.0)** — look-and-feel as a tracked deliverable on equal footing with function; four pillars (design-system foundation · ACP feel · member polish · editor flagship) threaded through 1.1/1.2/1.3; clean-room, tokens-only, gated incl. the a11y page gate; the editor upload path is apex (ADR-0093) | **Proposed** | [§ADR-0092](#adr-0092--design-polish-program-2026-06-22) · [program](design-polish-program-2026-06-22.md) · [kickoff](design-polish-kickoff.md) |
| 0093 | **Editor attachment subsystem (apex)** — extend (not duplicate) the existing image-upload/attachment infra with a drag-drop multi-file attach zone + a hardened upload/serve path; untrusted-input boundary, mandated adversarial review | **Proposed** | [§ADR-0093](#adr-0093--editor-attachment-subsystem-2026-06-22) · [kickoff §Slice 2](design-polish-kickoff.md) |
```

---

## Detail blocks (paste into the `## ADR detail` section)

### ADR-0092 — Design-Polish Program
**Context:** two external IPS audits found NovFora's design *foundation* is strong (semantic-token system, dual-mode
dark, density scaling, AA floor, Theme-API contract, an `<x-ui.*>` set) but **uneven** — a CSS class leak shipped the
audit's worst-rated defect (the `.novfora-prose` height cap on rendered posts, fixed as M0), editor richness is thin
vs. incumbents, and empty/loading/error states aren't systematic. The owner's directive: **form weighted equally with
function.** **Decision:** run a post-1.0 **Design-Polish Program** as a parallel, tracked track in four pillars —
(1) mature the token set into a documented, gap-filled `<x-ui.*>` library (adds `table` + `skeleton`; the rest already
exist), (2) ACP navigability/feel (persistent sidebar shell, the standard table, one form system, quick-links),
(3) member-experience polish, (4) a curated-rich, keyboard-first, **independently-designed (clean-room)** rich-text
editor as the flagship. It threads through the functional milestones 1.1 (member UX) / 1.2 (ACP v4) / 1.3 (admin
tooling); every change is **tokens-only** (auto dark + density) and passes the deterministic gates **including the a11y
page gate**; "done" includes visual + a11y, not just green tests (`design-polish-program-2026-06-22.md` §5).
**Consequences:** polish becomes reusable + regression-guarded rather than per-surface luck; ACP v4 + member-UX consume
the same components; **no new product scope** beyond the editor **attachment subsystem (ADR-0093)**, which is apex. Model
routing: mostly Sonnet (view/CSS/component sweeps via Explore sub-agents); the editor upload path is **Fable @ max**.

### ADR-0093 — Editor attachment subsystem
**Context:** the composer (`content-editor.blade.php` + the TipTap island) supports only **single, image-only,
click-to-pick** upload; the flagship needs the screenshot's **drag-and-drop, multi-file attach zone with a max-size
readout**. File upload from the internet is an **untrusted-input boundary** — the apex rung per `CLAUDE.md`. P5.1 shows
attachment handling already exists (the soft-deleted-post download gate; the importer path-traversal fix), so this
**extends, not duplicates** it. **Decision:** an additive/reversible `attachments` table (uploader · polymorphic/`post_id`
+ a pre-publish `draft_token` · disk · random stored path · original name · mime · size · checksum); a **permission-gated,
throttled upload endpoint** that enforces a size cap (surfaced in the UI), an **extension allowlist + MIME sniff** with
mismatch rejection, **image re-encode + EXIF strip + dimension clamp**, **inert storage + forced safe `Content-Disposition`**
for non-images, and sanitised stored filenames (reject `..`/schemes); a **serve endpoint** authorising on parent-content
visibility (**private-club no-leak**) and mirroring the **soft-deleted gate** (uploader/moderator only); **tier-aware
storage** (local disk Baseline / S3·MinIO Enhanced — no daemon dependency on Baseline); draft→post association on publish
+ an **orphan-prune cron** (`novfora:attachments:prune`, short-mutex, restore-skipped). The TipTap side adds a file-card
node (extending the `EmbedNode`/`SpoilerNode` pattern) and generalises the existing image drag/paste handlers to all
files; render stays canonical-JSON → server sanitise allowlist. **A per-finding adversarial verify-then-refute review is
mandatory before merge** (MIME/extension confusion, SVG/script, size/zip-bomb, traversal, serve IDOR, private-club leak,
unauth, rate-limit bypass, draft-token forgery), recorded like the P5.1/RH-4 reviews. **Consequences:** rich multi-file
attachments on the Baseline tier with no daemon, security-tested, reversible; a new public surface (the endpoints) that
the module/REST layers may later consume; the heaviest review burden in the program — correctly, since it takes bytes
from the internet.
