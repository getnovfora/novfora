<!-- SPDX-License-Identifier: Apache-2.0 -->

# Plan memo — v1.4 Phase 4D: Admin at scale + staff workflow (2026-07-18)

> Per kickoff §2.1 (plan-before-phase; approval pre-granted). Grounded in a five-agent recon of the existing
> registration / bans-IP / maintenance / topic-fields / staff-metrics surfaces. Build order (kickoff §5):
> **U14 → U13 → U16 → U19 → staff workflow.** One branch per slice off `main`, gated green, commit at a green
> boundary. **U13 gets the elevated apex review** (new IP-ban enforcement surface); the staff-workflow ADR is
> 0114 (kickoff-reserved). ADR next-free confirmed: 0112 (U11) + 0113 (Registry) taken this release; 0114
> reserved for staff metrics; **0115–0118 reserved for 4E**; **0119 free** (→ U14 activation policy); 0120 =
> Populate; **0121 free** (→ U13 CIDR bans).

## Ground truth (build on, don't rebuild)
- **Registration:** `RegistrationGuard` (tri-state allow/flag/block) sets `status='pending'` in `CreateNewUser`;
  `NewUserModeration::shouldHold()` condition A holds every pending user's posts forever; `TrustLevelManager`
  freezes a non-active account at its level. **No approval queue, no ToS/age gate, no domain allow/deny.**
  `registration_checks` stores the flag signal in `decision` + `provider_scores` (no dedicated reason column).
- **Bans:** `UserBanService` is the single ban/unban chokepoint with the S5 `OwnerStrandGuard`; the `bans` table
  already has `type user|ip|email|range` + `value`, but **`range` is stored and matched nowhere**;
  `RegistrationGuard::banned()` does exact ip/email match at registration only; `BanChecker` checks user bans
  only. IP is captured in `registration_checks`, `audit_log`, `posts`, `sessions`. The only ban UI is the
  per-member user-ban card. `/64` IPv6 bucketing precedent in `AppServiceProvider`.
- **Maintenance:** `TopicCounters` recompute exists but has no CLI/ACP entry (only merge/split call it);
  `users.post_count` has no self-heal; audit log is browsable, but no laravel.log viewer; two duplicate
  send-test-email impls.
- **Topic move:** `moved_to_topic_id` + `TopicController`'s transitive-301 is proven by `MergeTopicsService`
  (the only writer); plain forum-move (`ModerationController::move`) doesn't touch it (id-keyed URL is stable).
  Profile custom-fields (`CustomField`/`CustomFieldValue`) are the structural precedent for topic fields;
  `Prefix` (forum-scoped) is the closer one.
- **Staff/metrics:** `moderator_assignments` is per-forum *capability* delegation, NOT work-item assignment;
  `reports` has no pre-resolution assignee. All four health signals (first-response, unanswered %, staff load,
  retention) are **truthfully derivable today** over `topics`/`posts`/`audit_log`/`users` — no estimation.
  `AnalyticsService::METRICS` + `⚡analytics.blade.php::charts()` + `<x-ui.sparkline>` is the add-a-tile seam.

## U14 — registration controls + pending-member exit-ramp (NOV-112, branch `claude/v14-u14-registration`, **ADR-0119**)
**ADR-0119 first (the anti-spam-sensitive core, per `pending-member-review-kickoff.md` §4):** pin the
activation policy — default posture = **admin-in-the-loop, not silent auto-clear**; auto-activation is
optional, config-gated, default OFF, fires ONLY on K **mod-approved** posts (the human signal), NEVER a
banned/blocked account; "activate" = `status pending→active` + audit + trust recompute; reversible, never
touches `acl_entries`.
1. **Pending/flagged review queue** (ACP Members, `admin.access` + `users.manage`): list `status='pending'`
   users with the registration flag reason (from `registration_checks.decision` + `provider_scores`), join
   date, post + mod-approved counts, last post. Per-row **Activate** / **Reject** (→ `UserBanService`).
2. **`MemberActivationService` (xhigh):** `status→active` + audit + trust recompute (reuse Branch-2's per-user
   recompute) in one transaction; **actor-independent guard: never activate a banned/blocked account**;
   idempotent (already-active = no-op).
3. **Approve-post nudge:** in the moderation queue, when the author is `pending`, surface a CTA to activate.
4. **Optional auto-activation** (xhigh, config `novfora.antispam.auto_activation.*`, default off, K default 5):
   on post approval, if enabled + author pending + ≥K mod-approved + not banned → activate via §2.
5. **Registration controls:** `registration.require_approval` (all new signups land pending), a ToS/age-gate
   checkbox at registration (`registration.tos_url` / `registration.min_age`), and an email-domain
   **allow-list** (complements the existing deny blocklist) — settings + `RegistrationGuard`/`CreateNewUser`
   wiring. Additive, reversible.

## U13 — IP investigation + ban management (NOV-111, branch `claude/v14-u13-ip-bans`, **ADR-0121**, ELEVATED → apex review)
1. **`CidrMatcher` (`app/Support/Net/CidrMatcher.php`):** clean-room IPv4+IPv6 CIDR parse/contains via
   `inet_pton` + bitmask (the `/64` bucketing is the precedent). Bounded, total, refuses malformed input.
2. **Range-ban enforcement (the gap):** extend `RegistrationGuard::banned()` to also match `type=range` via
   `CidrMatcher`; add an **`IpBanGuard`** consulted at the abuse boundaries (registration + login + post
   create), backed by a **short-TTL cached list** of active ip/range bans so it's not a per-request DB hit
   (baseline-safe). A matched IP/range ban blocks the action with a neutral refusal.
3. **Ban management ACP** (Moderation section, `bans.manage` + staff-2FA): create/list/lift **ip / range /
   email** bans (the types with no UI today) with expiry; user bans stay routed through `UserBanService`
   (the real `OwnerStrandGuard`) — never duplicate `BanController`'s thinner guard.
4. **Per-user IP history + audit-log IP:** a member IP-history card unioning `audit_log` (actor + target),
   `posts.ip_address`, `sessions.ip_address`; add an IP column + filter to the audit-log SFC.
5. **Apex lenses:** CIDR match correctness (IPv4/IPv6 fuzz, malformed input, off-by-one on the mask boundary),
   the owner-strand path on any user-ban surface, PII exposure (IP history gated at the `users.manage`
   ceiling), a shared-NAT false-positive DoS, cache-poisoning of the ban list, enforcement bypass.

## U16 — maintenance/rebuild + logs + mail-test ACP (NOV-114, branch `claude/v14-u16-maintenance`)
ACP **System → Maintenance** SFC (`admin.system.access` + staff-2FA): **cache clear** (bounded, safe subset),
**counter rebuild** (new `novfora:forums:recompute-counters` command exposing `TopicCounters` + a
`users.post_count` self-heal, ACP-triggerable as a queued job on the baseline), a **log viewer** (bounded tail
of `storage/logs/laravel.log`, read-only, redacted), and a **mail-test** (consolidate the two existing
send-test impls into one service). Mostly Sonnet-rung; additive; no schema.

## U19 — custom topic fields + move-with-redirect (NOV-116, branch `claude/v14-u19-topic-fields`)
1. **Topic fields:** `TopicField`/`TopicFieldValue` (parallel to `CustomField`, **per-forum-scoped like
   `Prefix`** — a nullable `forum_id` = global-or-forum catalog; typed text/url/textarea/select), ACP CRUD,
   captured in `PostService::createTopic` + topic edit, rendered in the topic show header. Reversible migration.
2. **Move-with-redirect (wire `moved_to_topic_id`):** a mod move action with a **"leave a redirect"** option —
   moves topic T to the target forum AND, when chosen, creates a lightweight **shadow topic** in the source
   forum whose `moved_to_topic_id = T` + `status='moved'` (the phpBB shadow), so its URL transitively-301s to
   T via the proven `TopicController` path. Without the option it's the existing plain `forum_id` update.

## Staff workflow + Hearth metrics v1 (NOV-127, branch `claude/v14-staff-workflow`, **ADR-0114**)
1. **Assignment:** add nullable `assigned_to` + `assigned_at` to `reports` (+ `assignee()` relation) and a
   lightweight per-topic assignee (a `moderator_topic_assignments` table keyed `topic_id`→`assigned_to` to
   avoid touching the hot `topics` schema). Assign/claim/unassign actions in the mod queue (audited); a
   **workload view** = open assigned items per staff.
2. **Hearth metrics v1 — REAL signals only** (extend `AnalyticsService::METRICS` + `charts()` +
   `<x-ui.sparkline>`): **first-response time** (first-reply `created_at` − topic `created_at`),
   **unanswered-topics %** (`reply_count=0`), **staff response load** (`audit_log` moderation actions grouped
   by `actor_id`), **member retention cohort** (`users.created_at` vs `last_active_at`). Each tile omitted if a
   signal isn't truthfully derivable. New daily rollups (reversible), computed in the existing daily cron.

## Sequencing / gates / conflicts
Build U14→U13→U16→U19→staff; each: full suite + pint + phpstan(app/) 0 + migrate round-trip + a11y (new ACP
pages join the gate) green in `forum-dev` (route:clear first) before commit. U13 apex-reviewed before its green
boundary. New nav items land in `AdminNavigation` + `lang/en/admin.php` + `routes/web.php` + the
`AdminAccessWalkTest` sentinel (the expected release-merge conflict cluster). ADRs 0119 (U14), 0121 (U13), 0114
(staff) lifted on their branches.
