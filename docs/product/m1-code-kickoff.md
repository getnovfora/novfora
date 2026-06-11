<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# M1 — Claude Code kickoff prompt (Phase 1, Identity & access)

> Paste the block below into the **Claude Code** session to begin Phase 1 **M1**. The Phase 1 plan (M0–M5) is
> owner-approved; **M0 is done** (commit `de14e49`). M1 is the **permission-mask engine** milestone — the
> authorization spine, flagged in CLAUDE.md for deep reasoning. Authoritative specs:
> [phase-1-plan.md](phase-1-plan.md) §5 (M1); **[security-and-permissions.md](../architecture/security-and-permissions.md) §1**
> (the resolution algorithm — read this closely); [data-model-initial.md](../architecture/data-model-initial.md)
> §1/§4/§9 (schema + index); ADR-0006; [testing-strategy.md](../architecture/testing-strategy.md).

---

```
Begin Phase 1 — M1 (Identity & access). The Phase 1 plan is owner-approved; M0 is done. This milestone is
the permission-mask engine — the spine everything else authorizes against. Get it right over getting it fast.

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md, docs/PROJECT-BRIEF.md (standing rule). Then the M1 spec:
docs/product/phase-1-plan.md §5 (M1); docs/architecture/security-and-permissions.md §1 (THE resolution
algorithm — §1.2 is exact, implement it precisely); docs/architecture/data-model-initial.md §1 (users/sessions),
§4 (groups/permissions/acl_entries/roles), §9 (the composite acl_entries index); ADR-0006; and
docs/architecture/testing-strategy.md for the truth-table suite.

MODEL/EFFORT: Opus 4.8 at xhigh, and THINK HARD on the resolver and its tests — a subtle miss here is
expensive because every later feature authorizes through it. Reserve Sonnet for the mechanical auth/CRUD-ish
breadth once the engine design is settled.

Open with a SHORT M1 plan (auth foundation choice; the acl schema + resolver design; the caching/invalidation
approach; the scope-table seam you need to test resolution; the truth-table matrix), then proceed — no wait,
the plan is approved. First: `git status` will show this kickoff doc uncommitted; commit it (docs:) then build.

BUILD M1 — three parts:

1) AUTH (server-rendered, baseline-safe, clean-room UI):
   • register / email-verify / login / sessions with **argon2id**; password reset; rate-limited login + reset
     (security §4). Prefer a headless/back-end auth (e.g., Laravel Fortify) with OUR OWN Blade/Livewire views
     so the theme layer owns the UI — do NOT pull a heavy SPA starter kit. Clean-room: our markup, not copied.
   • **2FA/TOTP for admin & moderator accounts (Must)** — enrollment + recovery codes; general-user opt-in is
     Should/Phase 2 (build the capability so it extends, gate the requirement to staff now). Vet the TOTP/QR
     lib license (MIT/BSD/Apache, ADR-0015) and record it in DECISIONS.md.

2) THE PERMISSION-MASK ENGINE (ADR-0006 / security §1) — the core of M1:
   • Schema (data-model §4): `groups` (is_system, priority, auto_promotion JSON, nullable tenant_id),
     `permissions` (key catalog, scope_kind), `acl_entries` (holder_type user|group, holder_id, scope_type,
     scope_id?, permission_key, value ALLOW=+1/NO=0/NEVER=-1), `roles`/`role_permissions`. Add the composite
     `acl_entries(holder_type,holder_id,scope_type,scope_id,permission_key)` index (data-model §9).
   • Resolver = security §1.2, implemented EXACTLY:
       (a) banned (global or scope) → DENY;
       (b) holders = user ∪ primary+secondary groups; scope chain = global→category→forum→thread (root→target);
       (c) **NEVER is absolute**: if any entry across all holders/scopes is NEVER → DENY (short-circuit);
       (d) else most-specific scope first: user's own entry overrides group; among groups MAX wins
           (most-permissive); unset → inherit to parent; (e) default → DENY (deny-by-default).
   • Caching (security §1.3): per-request memoization + a resolved-set cache keyed by the user's group-set
     signature + a global ACL version counter; bump the version on any group/role/ACL change (event-driven
     invalidation). Baseline = file/DB cache; **correctness must never depend on the cache** (read-through,
     graceful miss); >95% hit is the target, not a correctness condition.
   • The "why can/can't X" inspector (security §1.4): given (user, permission, scope), render the full
     resolution trace — every contributing entry (holder→scope→value), whether/where a NEVER blocked it, the
     deciding holder/scope, and the verdict. Build it now: it's an admin tool AND the oracle for the tests.
   • Expose via Laravel Gate/policies, deny-by-default.

3) SEEDS: role presets (admin / moderator / member / guest) + trust-level system groups (TL0…) with
   auto_promotion rules. Trust levels ARE ACL groups (the M3 anti-spam hook) — establish the groups + the
   gating primitive now (e.g., TL0 can carry restrictive entries), but the anti-spam ENFORCEMENT/promotion
   automation lands in M3; don't build M3 here.

DEFINITION OF DONE (the truth-table suite is non-negotiable, per testing-strategy + plan §6):
   • Fill the M0 placeholder `PermissionMaskTest` with an EXHAUSTIVE truth table: ALLOW/NO/NEVER × the
     scope chain × group-merge, plus the security §1.5 edge cases — guests-as-group; primary-vs-secondary
     decided by most-permissive+NEVER (not group order); deleted/moved scope inherits from surviving parent;
     role change re-expands + bumps the version; a user in two groups (ALLOW + NEVER) → DENY. Use the
     inspector trace as the oracle.
   • App runs on the baseline tier (PHP 8.3 + MySQL + cron); the M0 service-tier fallback tests stay green;
     all CI guards pass (Pint, Larastan, Pest, audit, budgets). Small conventional DCO commits; update
     PROJECT-STATE; keep .env.example current.

SCOPE FENCE — build ONLY M1. The resolver scopes against global→category→forum→thread, so create the MINIMAL
structural node tables it needs to attach acl_entries and run the truth-table tests (per data-model §2);
**full forum CRUD, views, the editor, and content storage are M2** and build on this. Not in M1: anti-spam
enforcement + moderation queue/ACP (M3), notifications/search/SEO/theme (M4). Add the nullable tenant_id seam
(ADR-0004) on users/groups/acl; do NOT build tenancy. Security-by-default throughout (argon2id, CSRF, strict
CSP, rate limits, audit log). Strict clean-room. When M1 lands runnable + tested, report back here.
```

---

## When M1 reports back

The Cowork session reviews M1 (especially: does the truth-table suite actually cover the §1.5 edge cases, and
is the cache provably non-load-bearing for correctness?), updates PROJECT-STATE, and preps the **M2** kickoff —
forum structure → content → **porting the validated editor pattern + `CanonicalRenderer`** from `nevo-spike/`
(which then retires). M1's permission engine becomes the per-node authorization M2's CRUD gates against.
