# Security & Permissions

> **Project:** NovFora (working codename). **Stage A deliverable** (Section 8 #10). **Date:** 2026-06-01.
> The **permission-mask resolution engine** (ADR-0006), the **first-class anti-spam subsystem** (ADR-0007 —
> a standalone, fully-specified ADR), the **moderation model**, and the **OWASP security baseline**.
> Storage lives in [data-model §4/§5/§6](data-model-initial.md); this doc owns *semantics, algorithms, and
> policy*. Permission-mask resolution and service-tier fallbacks get **dedicated tests**
> ([testing-strategy](testing-strategy.md)).

---

## 1. Permission-mask resolution (ADR-0006)

The phpBB-grade ACL is a **primary requirement**, not a generic gate. It is reimplemented clean-room.

### 1.1 Model

- **Three-state values:** every permission, for every holder, at every scope, is **ALLOW (`+1`)**, **NO
  (`0`, neutral/unset)**, or **NEVER (`-1`)**. **NEVER is absolute** — no ALLOW anywhere can override it.
  - **NO = neutral / inherit (interpretation "ii") — owner-confirmed 2026-06-02.** A `NO` never hard-denies:
    an `ALLOW` (a more-specific scope or a higher-priority group) lifts it, and inheritance continues *past*
    it. Use **NEVER** to hard-deny. This is implemented exactly in `PermissionResolver::compute()` (the single
    decision point is marked inline) and pinned by the permission-mask truth-table suite.
- **Holders:** **users** and **groups**. Users have **one primary + N secondary** groups. **Guests** are a
  system group; **banned** is enforced before resolution.
- **Roles** are *presets* — named bundles of three-state values applied to a holder at a scope (they expand
  into ACL entries; they are not a separate evaluation layer).
- **Scopes form a hierarchy:** **global → category → forum → (thread)**. A more-specific (local) scope
  **overrides** a less-specific one; an unset local value **inherits** from its parent.

### 1.2 Resolution algorithm

For `can(user, permission, target_scope)`:

```
1. If user is banned (globally or for this scope) → DENY.
2. Collect HOLDERS = { user } ∪ user.groups (primary + secondary).
3. Collect SCOPE_CHAIN = [global, …ancestor categories…, forum, thread]  (root → target).
4. Gather ENTRIES = all acl_entries for (holder ∈ HOLDERS, permission, scope ∈ SCOPE_CHAIN).
5. NEVER is absolute:  if any entry in ENTRIES has value = NEVER → DENY.   // short-circuit
6. Otherwise resolve by precedence, most-specific scope first:
     for scope in SCOPE_CHAIN reversed (thread → … → global):
         user_val  = value of the user's own entry at this scope (if any)
         group_val = MAX(value of group entries at this scope)   // ALLOW beats NO among groups
         if user_val is set:  return (user_val == ALLOW)         // user overrides group at same scope
         if group_val is set: return (group_val == ALLOW)        // most-permissive group wins
         // else: nothing set at this scope → inherit (continue to parent scope)
7. Default (no entry granted anywhere) → DENY.                   // deny-by-default
```

**Key properties:** NEVER (step 5) is checked across *all* holders and scopes and always wins — this is the
hard-gate primitive (used by anti-spam, §2). Absent a NEVER, **local overrides global** and **user overrides
group**, with **most-permissive-group** within a tier. The default is **deny**.

### 1.3 Caching & performance

- Resolution for a user is **memoized per request** and **cached** as a resolved permission set per
  (user, scope), keyed by the user's **group-set signature + a global ACL version counter**. Any
  group/ACL/role change bumps the version (event-driven), invalidating stale sets.
- Target: **>95% permission-cache hit** ([system-architecture §7](system-architecture.md)); group permission
  sets are precomputed so a typical check reads few rows via the composite
  `acl_entries(holder_type,holder_id,scope_type,scope_id,permission_key)` index.
- Baseline uses file/DB cache; enhanced uses Redis — **correctness never depends on the cache** (read-through
  with graceful miss).

### 1.4 The "why can / can't this user do X?" inspector (brief requirement)

Given `(user, permission, scope)`, the admin inspector renders the **full resolution trace**: every
contributing entry (holder → scope → value), whether a **NEVER** blocked it (and from where), which scope/holder
ultimately decided it, and the final verdict. This makes the otherwise-opaque mask debuggable — a feature the
incumbents lack and admins beg for — and doubles as the oracle for the permission tests.

### 1.5 Edge cases (explicitly handled & tested)

Guests-as-group; primary-vs-secondary precedence (handled by most-permissive + NEVER, not group order);
deleted/moved scopes (inherit from surviving parent); role changes (re-expand + version bump); a user in
multiple groups where one says ALLOW and another NEVER → **DENY** (NEVER wins).

---

<a id="anti-spam"></a>

## 2. Anti-spam subsystem (ADR-0007) — *standalone ADR*

> **Status:** Accepted (Stage A). **Why standalone:** spam is the **#1 evidenced operator burden**
> ([complaints C1](../research/community-complaints-and-feature-requests.md)) and NovFora's headline
> differentiator — it is a first-class subsystem, not a settings page. Phased: baseline in **Phase 1**,
> intelligence in **Phase 4**.

### 2.1 Principle: layered defense, unified with permissions, graceful by tier

Defense-in-depth across **registration → trust → posting → reaction**, every layer **baseline-safe** (degrades
gracefully if an external service is absent — the brief's rule), and **gating expressed through the
permission-mask engine** rather than a parallel system.

### 2.2 Layer 1 — registration

| Control | Behavior | Default | Degradation |
|---|---|---|---|
| **Crowdsourced blocklist** (StopForumSpam-style) | check IP/email/username vs a **cron-cached** blocklist + optional live API; **configurable confidence threshold** | **block** if high-confidence listed; **flag→moderate** if borderline | if API down → use cached list + heuristics |
| **Disposable-email block** | reject known temp-mail domains (maintained list) | on | local list, always available |
| **Honeypot + timing** | hidden field + min-fill-time | on (form too fast/honeypot filled → reject) | local, always |
| **CAPTCHA (provider-swappable)** | see §2.5; configurable per action | **Q&A or invisible** on register | Q&A needs no external service |
| **IP risk / velocity** (MaxMind optional) | per-IP/subnet/country reg-rate limits | flag on velocity spikes | velocity rules are local |
| **Email verification** | required before full participation | on | local |

Outcomes are tri-state to mirror the ACL: **allow / flag (→ moderation queue) / block**. **Uncertain cases
flag, not block** — losing a real member is worse than one extra moderated post.

### 2.3 Layer 2 — trust levels, and how they interact with the permission-mask engine *(the crux)*

Trust levels (TL0…TLn, Discourse-inspired) are implemented as **automatic, system-managed groups**, promoted by
the same `auto_promotion` rules as any group ([data-model §4](data-model-initial.md)). **Because trust levels
are groups, new-user gating runs entirely through the existing ACL (§1) — there is no second permission
system.**

- **Gating = permission entries on TL groups.** TL0 carries restrictive entries; higher TL groups grant more:

  | Capability (example) | TL0 (new) | TL1 | TL2+ | Value type |
  |---|---|---|---|---|
  | post in public forums | ALLOW (often moderated first N) | ALLOW | ALLOW | — |
  | include links/images | **NEVER** | ALLOW | ALLOW | **hard gate** |
  | upload attachments | NO | ALLOW | ALLOW | soft gate |
  | send PMs / mass-PM | **NEVER** (PMs) | ALLOW (limited) | ALLOW | hard/soft |
  | post rate | tightest tier | looser | loosest | rate policy |

- **NEVER vs NO is a deliberate design choice (tradeoff):**
  - **NEVER** = an **absolute hard gate** even an admin's per-user/per-forum ALLOW cannot lift (§1.2 step 5).
    Use for capabilities that must *never* be available to untrusted accounts regardless of local config
    (e.g., links in the first hour, mass-PM) — this is exactly the spam-vector lockdown that NEVER's
    absoluteness exists for.
  - **NO** = a **soft gate** a higher-priority group or an explicit admin ALLOW *can* lift — so a trusted
    private community can choose to grant TL0 more in a specific forum.
  - The anti-spam defaults pick NEVER only for true spam vectors; everything else is NO, to avoid over-locking
    legitimate users. **This choice is documented per capability and is itself configurable.**
- **Inspectable & overridable:** because it's all ACL, the **"why can't X" inspector** explains a new-user
  block precisely ("blocked by TL0 group: `post.links = NEVER`"), and admins tune gates per-forum within the
  NEVER/NO rules.

**Default promotion thresholds (Discourse-inspired, all configurable):** TL0→TL1 after reading ≥ ~5 topics and
≥ ~10 minutes with ≥ 1 post and **no active flags**; TL1→TL2 after sustained participation over days with no
infractions. Demotion on flags/infractions. (Concrete numbers ship as seeded defaults, tunable in the ACP.)

### 2.4 Layers 3–4 — posting & reactive tools

- **Post-time:** pluggable **content scanning** (Akismet-style) → flag/queue *(the scanning **contract** is built in Phase 1 with the anti-spam baseline — shipping local heuristics behind it; the **Akismet provider** integration ships in Phase 2)*; **rate limiting** per trust tier
  (DB-backed baseline, Redis enhanced); **new-user moderation queue** (first **N=2** approved posts by default,
  configurable); link/image suppression for TL0.
- **Reactive:** **Spam Cleaner** (one action bulk-removes a flagged account's content **and** bans — XF concept);
  report→queue; **user/IP/email/range bans**; word filters; full audit-logged.

### 2.5 CAPTCHA provider abstraction (brief requirement)

A `CaptchaProvider` contract with built-ins and pluggables, **selectable per action** (register, post,
contact, password-reset):

- **Q&A challenge** — admin-defined questions; **no external dependency** (baseline-friendly, resists models
  trained on public CAPTCHA datasets).
- **Honeypot / invisible** — local, frictionless.
- **Pluggable external** — hCaptcha / reCAPTCHA / Cloudflare Turnstile (enhanced; external HTTP) — **degrade to
  Q&A** if the provider is unreachable. New providers are modules implementing the contract.

### 2.6 Tradeoffs & risks (required by the quality bar)

- **Strictness vs friction:** aggressive thresholds cut spam but raise false-positives. Mitigation: moderate
  defaults, **flag-don't-block on uncertainty**, everything configurable, and clear admin metrics on
  block/flag rates.
- **External-service dependence:** StopForumSpam/Akismet/MaxMind/Turnstile improve accuracy but are network
  dependencies → **all must degrade gracefully** to local heuristics + moderation (never error). Baseline never
  *requires* them.
- **Privacy/GDPR:** IP and risk checks process PII. `registration_checks` carry a **retention policy** (configurable
  purge), IP logging is disclosed, and risk-provider use is documented for the privacy policy.
- **Maintenance:** blocklist/temp-mail freshness via cron; provider API drift handled behind the contract.
- **Why this beats the incumbents:** it's **baked into the registration/posting/permission flow** rather than a
  bolt-on, unified with the ACL, and tier-graceful — directly answering the evidence that even fully-configured
  incumbent stacks get overrun.

---

## 3. Moderation model

- **Inline moderation in thread view *and* a queue** (both, not only a queue): per-item staff actions
  (approve/delete/move/merge/split/lock/pin) plus **cross-page persistent bulk selection** (XF concept).
- **Reports → staff dashboard**; resolution logged.
- **Warnings/infractions:** typed, point-weighted, **time-decaying**, with **automated consequences** at
  thresholds (restrict → moderate → temp ban → ban) and **required acknowledgment** (IPS concept) before
  posting is restored.
- **Approval workflows** via content `approved_state`; **soft-delete + recycle bin + restore**; **word filters**;
  **bans** (user/IP/email/range).
- **Complete audit log** of every staff/system action ([data-model §5](data-model-initial.md)).

## 4. Security baseline (OWASP Top 10, brief hard rules)

- **AuthN:** **argon2id** hashing; secure session cookies; optional **2FA/TOTP** (later phase); rate-limited
  login + password reset.
- **AuthZ:** the ACL (§1) via Laravel policies/gates; **deny-by-default**.
- **Injection:** Eloquent **parameterized queries** only; raw SQL only where measured and justified
  ([CLAUDE.md] rule), reviewed.
- **XSS:** rich text is **server-side sanitized from the canonical source** via an allowlist (ADR-0005);
  client HTML never trusted; **strict CSP**.
- **CSRF:** Laravel tokens on all state-changing requests.
- **Rate limiting & abuse:** per-route + anti-spam tiers.
- **Uploads:** MIME/type validation, size limits, image re-encoding/thumbnailing, stored off the web root /
  on S3; checksum recorded.
- **Audit logging** of security-relevant events; **structured logs**; health checks.
- **Privacy/GDPR:** data **export**, **account deletion**, consent/cookie management, configurable **retention**
  (incl. `registration_checks`, IP logs, audit log).
- **Transport/secrets:** HTTPS-only cookies; secrets in `.env`; signed URLs for sensitive links.

## Cross-references

Storage: [data-model](data-model-initial.md) · Tiered fallbacks (rate-limit/cache drivers):
[system-architecture](system-architecture.md) · Modules registering permissions/CAPTCHA providers:
[plugin-and-theme-system](plugin-and-theme-system.md) · Dedicated permission & fallback tests:
[testing-strategy](testing-strategy.md).
