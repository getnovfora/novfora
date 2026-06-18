<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# ADR-0080 (final draft) — ready to lift into `DECISIONS.md`

> Confirm the number is still free when Code starts (0078/0079 are merged; 0080 should be next). Child ADRs
> 0081… land per slice.

---

### ADR-0080 — ACP v3: admin & permission management architecture (parent) (2026-06-18)

**Status: Accepted — owner-approved program (2026-06-18). Implementation lands per slice with child ADRs
(0081…), each gated + flagged for review.**

**Context.** NovFora has a phpBB-grade permission **engine** (ADR-0006: ALLOW / NO (neutral) / NEVER,
`RoleExpander`, `PermissionResolver`, `PermissionSync`, scopes global/forum/club) and a read-only
`PermissionInspector`, but the admin-facing **management UX** is minimal — permissions are shaped through seeded
presets and the v2 groups manager. The ACP v3 spec (`docs/product/acp-v3-spec.md`, 2026-06-10) designs the full
management layer but predates Clubs, the inspector, and ADR-0025, and proposes parallel tables that would risk a
second evaluation path. The reconciliation + slice plan is `docs/product/acp-v3-kickoff-refresh.md`; the locked
foundations (section taxonomy + the engine seam) are `docs/product/acp-v3-foundations.md`.

**Decision.** Build ACP v3 as a multi-slice program **on top of the existing engine**, under binding guardrails:
(G1) every new construct is stored as / expands into `acl_entries` and resolves through the one resolver — **no
parallel evaluation**; (G2) global / forum / **club** scope throughout; (G3) additive, reversible migrations;
(G4) `PermissionInspector` is the **correctness oracle** for every write-path test; (G5) apex security fences;
(G6) **Fable @ max** for any `acl_entries` / resolver / delegation slice; (G7) i18n `admin.*` from day one;
(G8) never name a `lang` group that case-collides with a bare `__('Word')` string-key (ADR-0079).

Settled product/architecture choices: **top-level = multiple co-owners** (no single Root, no transfer protocol)
protected by a **last-owner guard** (the `isSoleAdmin` locked-re-check pattern, ADR-0025); **inheritance = Global
→ Forum (+ Club)** with a bulk "apply to every forum in this category" action (**no category scope**);
**temporary-access delegation = a TTL on `acl_entries`** (additive nullable `expires_at` the resolver honours,
auto-expiring, ceiling-bounded, 30-day cap shown, cron-pruned); **group auto-promotion = a full AND/OR builder**
(promotion-only); **per-forum moderator capabilities = preset bundles + a custom path** (custom reuses the role
builder); the **ACP nav restructure** (Invision-style icon rail + per-section dashboards) is **in this cycle**,
sequenced as an independent track. A single **ACP section taxonomy** (foundations §3) is the shared contract for
the nav and the `admin.<section>.access` bundles.

The only change reaching the locked engine is additive: a nullable, indexed `acl_entries.expires_at` with a
single resolver filter (`expires_at IS NULL OR > now`), a cron prune that bumps `AclVersion`, and extended
truth-table / inspector coverage. **The filter is authoritative** — a lagging sweep never honours an expired
grant. The delegation **ceiling invariant** (recipient never exceeds the delegator's current mask; co-owner never
delegable) is the apex test of the delegation slice.

**Slice program (child ADRs, validated against migration dependencies before each locks):** v3-0 foundations +
the `expires_at` seam (this ADR) → **v3-h** nav shell + IA → **v3-c** card-per-group editor → **v3-e** group
system (AND/OR) → **v3-d** custom roles → **v3-b** moderator assignment → **v3-a** co-owners + Admin Manager +
bundles → **v3-f** delegation → **v3-g** staff flair / roster.

**Consequences.** The engine and inspector remain the single source of truth, changed only by the additive
`expires_at` seam; everything else is additive surface + projections. Old ACP routes 301 to their new section
homes. The nav restructure ships in-cycle but decoupled, so a slip never blocks the permission features.
Per-section access keys arrive with the bundles slice (v3-a); until then the new rail is visible to any admin and
the Security section houses the existing inspector under its current gate.

**Alternatives considered.** (a) Implement the spec verbatim with parallel `admin_permissions` / moderator
evaluation — rejected (violates G1, splits the security-critical path). (b) Single founder Root + 4-rail transfer
protocol — rejected by owner for multiple co-owners. (c) A true category permission scope — deferred
(engine / `ScopeChain` change; bulk apply-to-category covers the ergonomics). (d) Resolver-overlay delegation —
rejected for TTL-on-`acl_entries` (one eval path). (e) Ship as one monolith — rejected (unreviewable).

**Scope fences.** No multi-tenant admin model; no marketplace/payments admin; a true category scope is deferred.
The nav restructure (v3-h) **is** in scope (owner decision).

**References.** `docs/product/acp-v3-foundations.md`, `docs/product/acp-v3-kickoff-refresh.md`,
`docs/product/acp-v3-spec.md`.
