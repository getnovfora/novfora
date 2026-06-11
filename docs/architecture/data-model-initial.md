# Initial Data Model

> **Project:** NovFora (working codename). **Stage A deliverable** (Section 8 #8). **Date:** 2026-06-01.
> The initial relational design: identity, structure (categories→forums→topics→posts), the **permission-mask
> ACL**, moderation/audit, anti-spam, messaging/notifications, engagement, extensibility (modules/themes),
> settings, and the **monetization seam**. Includes the **canonical content-storage decision (ADR-0005)**, the
> **`tenant_id`** convention, **i18n/RTL/multibyte** safety (ADR-0016), and **indexing/partitioning** for the
> medium→large path. Resolution semantics for permissions live in
> [security-and-permissions](security-and-permissions.md); this doc owns the *storage*.
> Engine: **MySQL 8 / MariaDB by default, PostgreSQL parity on Docker/VPS (ADR-0004)**.

---

## 0. Cross-cutting conventions (apply to every table)

- **PKs:** `BIGINT UNSIGNED AUTO_INCREMENT` (`id`). Public-facing content also carries a **slug** for SEO URLs.
- **Soft deletes + recycle bin:** `deleted_at` on user-content tables; a recycle-bin view + restore; hard-purge
  is a separate, audited maintenance job.
- **Timestamps & actors:** `created_at`/`updated_at`; mutable content also tracks `edited_at`,`edited_by`.
- **Charset/collation (ADR-0016):** **`utf8mb4`** everywhere (full Unicode incl. emoji & CJK);
  `utf8mb4_0900_ai_ci` (MySQL 8) / `utf8mb4_unicode_ci` (MariaDB). **Nothing about storage precludes RTL or
  multibyte** — text direction is a *render-time* concern derived from content/UI language, not the schema.
- **Multi-tenant seam (`tenant_id`):** every **top-level** table carries a **nullable `tenant_id BIGINT`**
  (users, forums, topics, posts, groups, settings, modules, themes, …). A global Eloquent scope is wired but
  **no-ops when `tenant_id` is null** (single-community installs leave it null). This is the cheap insurance
  the brief mandates: tenant scoping can be switched on later **without a schema rewrite**. SaaS remains out of
  scope.
- **Counters:** denormalized counts (`reply_count`, `post_count`, `topic_count`, `last_post_id`) are maintained
  via model events, never `COUNT(*)` on hot paths.

---

## 1. Identity & users

| Table | Key columns | Notes |
|---|---|---|
| `users` | username, slug, email, email_verified_at, password (argon2id), display_name, avatar_path, cover_path, signature_doc, **trust_level**, reputation_points, post_count, last_active_at, timezone, locale, status (active/pending/suspended/banned), tenant_id | `trust_level` is both an engagement and an **anti-spam** lever (ADR-0007). `locale` enables per-user language. |
| `custom_fields` / `custom_field_values` | field key, label, type, options, position / (user_id, field_id, value) | Admin-defined profile fields; labels are translatable (see §10). |
| `user_preferences` | user_id, key, value (or JSON) | Notification prefs, display options. |
| `user_follows` / `user_ignores` | (actor_id, target_id) | Follow / ignore. |
| `staff_notes` | user_id, author_id, note | Private mod notes on a member. |
| `sessions`, `password_reset_tokens`, `personal_access_tokens` | Laravel defaults | Sanctum tokens for API (Phase 3); OAuth via Passport (Phase 4). |

## 2. Structure: categories → forums → topics → posts

**Hierarchy (ADR-0004 note):** `forums` is **self-referential** (`parent_id`) supporting categories, forums,
and unlimited nesting, plus a cached **`path` + `depth`** for cheap ancestor/subtree queries and a `position`
for ordering. Adjacency-list + cached path is chosen over nested-set because the forum tree is small and
rarely reordered; subtree reads stay O(depth) via `path`.

| Table | Key columns | Notes |
|---|---|---|
| `forums` | parent_id, slug, title, description, **type (category/forum/link)**, path, depth, position, icon, color, settings(JSON), is_locked, topic_count, post_count, last_post_id, **club_id?**, tenant_id | Per-node permissions resolved against this scope (security doc). `club_id` reserves the Clubs seam (Phase 4). |
| `topics` | forum_id, user_id, slug, title, **type (normal/sticky/announcement/global)**, **status (open/locked/moved/merged)**, is_pinned, first_post_id, last_post_id, reply_count, view_count, **prefix_id?**, poll_id?, moved_to_topic_id?, **approved_state**, tenant_id | Sticky/announcement/locked/moved/merged/split all representable. `approved_state` feeds the mod queue. |
| `posts` | topic_id, user_id, parent_post_id?, **body_format**, **body_canonical**, **body_html_cache**, **body_text** (search projection), ip_address, edited_at, edited_by, edit_count, approved_state, position, tenant_id | Content storage per **ADR-0005** (§3). `parent_post_id` reserves optional threaded replies. |
| `post_revisions` | post_id, editor_id, body_canonical, reason, created_at | Edit history + diffs. |
| `topic_prefixes` | scope (global/forum), label, color | XenForo-style prefixes. |
| `tags`, `taggables` | tag, slug / (taggable_type, taggable_id, tag_id) | Discourse-style tags **coexisting** with hierarchical categories (brief requirement). |
| `polls`, `poll_options`, `poll_votes` | … | Inline polls. |
| `attachments` | post_id?, user_id, disk, path, mime, size, width, height, thumbnail_path, **checksum**, download_count, tenant_id | `checksum` powers importer **attachment verification** (complaint C7). `disk` = local (baseline) or S3 (enhanced). |
| `reactions`, `reaction_types` | (user_id, reactable_type, reactable_id, reaction_type_id) / label, icon, **weight (pos/neg/neutral)** | Reaction score = Σ positive − Σ negative (XF concept). |

## 3. Canonical content storage — ADR-0005

**Decision:** store a **structured, source-of-truth canonical document** + a **server-sanitized rendered-HTML
cache** + a **plain-text projection** for search. Concretely, each post/signature/message carries:

- **`body_format`** — `tiptap_json` (default WYSIWYG) · `markdown` (power-user mode) · `bbcode_legacy` /
  `html_legacy` (imports).
- **`body_canonical`** — the lossless source in its native format (TipTap/ProseMirror **JSON** for WYSIWYG;
  Markdown text for markdown mode; legacy BBCode/HTML for imports). Editing reopens *this*, losslessly.
- **`body_html_cache`** — display HTML, **always (re)generated and sanitized server-side** from the canonical
  via an **allowlist sanitizer**. Client-submitted HTML is **never** trusted (the security boundary is the
  server). Re-rendered on demand when render rules/embeds change.
- **`body_text`** — tags-stripped plain text for search indexing (Scout) and excerpts.

**Why (trade-offs):**

| Option | Edit fidelity | Render safety | Re-transform | Search | Verdict |
|---|---|---|---|---|---|
| Sanitized HTML only | lossy on re-edit | good | hard | strip tags | rejected (can't safely re-transform/embed) |
| Markdown only | lossy for rich nodes | good | ok | easy | rejected (WYSIWYG round-trip lossy) |
| **Canonical doc + html cache + text (chosen)** | **lossless** | **good (server sanitize)** | **easy (re-render)** | **easy (text col)** | **chosen** |

WYSIWYG JSON↔HTML conversion runs **server-side** via a maintained **MIT** PHP library (e.g. `tiptap-php`),
with the allowlist sanitizer's license vetted in [DECISIONS.md](../../DECISIONS.md). BBCode imports either
convert to canonical on import or render through a BBCode→HTML parser, with optional one-time migration. This
satisfies the brief's "store sanitized content in a normalized format and render safely," keeps RTL/multibyte
intact, and future-proofs re-rendering.

## 4. Permissions, groups & roles (storage; resolution in the security doc)

The phpBB-grade ACL is the platform's spine. Storage is normalized so resolution can merge efficiently.

| Table | Key columns | Notes |
|---|---|---|
| `groups` | name, slug, type, color, is_system, priority, **auto_promotion(JSON)**, tenant_id | System groups: Guests, Registered, Moderators, Admins. Auto-promotion by post-count/trust/tenure. |
| `group_user` | group_id, user_id, **is_primary** | **Primary + secondary** membership (brief requirement). |
| `permissions` | key, label, **scope_kind (global/category/forum/thread)**, group, description | Catalog of permission keys (defined in code, persisted for reference & the inspector). |
| `acl_entries` | **permission_key, holder_type (user/group), holder_id, scope_type (global/forum/…), scope_id?, value (ALLOW=1 / NO=0 / NEVER=-1)** | The heart of the model: one row = (holder, permission, scope, three-state value). Composite index drives resolution. |
| `roles`, `role_permissions` | name, is_preset / role_id, permission_key, value | **Role presets** (e.g., "Standard Moderator") = reusable bundles of three-state values. |
| `role_assignments` | role_id, holder_type, holder_id, scope_type, scope_id? | Apply a role to a user/group at a scope. |

`value` encodes phpBB's **YES / NO / NEVER** (NEVER is absolute). Resolution order, caching (>95% hit target),
and the **"why can/can't this user do X" inspector** are specified in
[security-and-permissions](security-and-permissions.md) (ADR-0006).

## 5. Moderation, discipline & audit

| Table | Key columns | Notes |
|---|---|---|
| `reports` | reporter_id, reportable_type/id, reason, status, handled_by, resolution, tenant_id | Report system → staff dashboard. |
| `mod_actions` | actor_id, action, target_type/id, data(JSON), tenant_id | Inline + queued moderation actions. |
| `warning_types` | label, default_points, **default_action(JSON)** | Pre-defined "action bundles" (IPS concept). |
| `warnings` | user_id, issued_by, warning_type_id, points, content_ref, **expires_at**, **acknowledged_at** | Points **expire** (time-decay); accumulation → automated consequences; **required acknowledgment** (IPS). |
| `bans` | bannable_type (user/ip/email/range), value, reason, expires_at?, created_by | User/IP/email/range bans. |
| `word_filters` | pattern, replacement, action | Word filters. |
| `audit_log` | actor_id, action, auditable_type/id, changes(JSON), ip, created_at, tenant_id | **Complete audit trail** (append-only; partition candidate — §9). |

## 6. Anti-spam (storage; design in ADR-0007)

| Table | Key columns | Notes |
|---|---|---|
| `registration_checks` | ip, email, username, **provider_scores(JSON)**, decision (allow/flag/block), created_at | StopForumSpam-style + content-scan results at signup. |
| `blocklist_cache` | type (ip/email/username), value, source, expires_at | Cached crowdsourced blocklist; refreshed via cron. |
| `rate_limit_hits` | key, window, count | DB-backed rate limiting on baseline (Redis on enhanced). |
| (trust levels) | `users.trust_level` + config | New-user gating interacts with the **permission-mask engine** — detailed in ADR-0007. |

## 7. Messaging & notifications

| Table | Key columns | Notes |
|---|---|---|
| `conversations`, `conversation_user`, `conversation_messages` | … / (conversation_id, user_id, last_read_at) / … | **Multi-participant PMs**. |
| `notifications` | id (UUID), type, notifiable_type/id, **data(JSON)**, read_at, created_at | Laravel notifications; `data` enables **merging** ("X new replies in [thread]"). Real-time on enhanced, polling on baseline. |
| `notification_preferences` | user_id, event_type, channel (db/mail/push), enabled | Granular per-event prefs + digests. |
| `push_subscriptions` | user_id, endpoint, keys | WebPush (enhanced/PWA, Phase 4). |
| `email_suppressions` | email, reason (bounce/complaint), created_at | Deliverability suppression list (ADR-0014). |

## 8. Engagement, extensibility, settings & monetization seam

| Table | Key columns | Notes |
|---|---|---|
| `badges`, `user_badges` | name, **criteria(JSON)**, points, icon / (user_id, badge_id, awarded_at) | Trophy/criteria engine → auto-award (XF concept). |
| `modules` | name, slug, version, **api_version**, enabled, settings(JSON), installed_at, tenant_id | Registry of installed modules; `api_version` powers the upgrade-compat check (ADR-0008). |
| `themes` | name, slug, **parent_theme_id**, version, is_active, **settings(JSON = style tokens)**, tenant_id | Visual-configurator tokens live here; developer Blade overrides live on the **filesystem** child-theme dir, not the DB (ADR-0009). |
| `settings` | key, value, type, group, autoload | Global settings store (autoloaded subset cached). |
| `subscription_plans`, `subscriptions` | … / user_id, plan_id, status, **grants_group_id**, expires_at | **Monetization seam (Phase 4)**: subscription → group → permission pipeline (IPS concept). Payments via Cashier/Stripe. |
| `clubs`, `club_members` | owner_id, name, **visibility (open/closed/private/paid)** / (club_id, user_id, role) | **Clubs seam (Phase 4)**; `forums.club_id` links club sub-forums. Modeled now so it isn't precluded. |

## 9. Indexing & partitioning (medium → large)

Forums are **~95% reads**; indexing targets the listing and resolution hot paths.

- **Listing:** `topics(forum_id, is_pinned, last_post_at)` (forum view); `posts(topic_id, created_at)` (thread
  view); `topics(last_post_at)` ("what's new"). Eager-load authors/reactions to honor the ≤30-query budget
  ([system-architecture §7](system-architecture.md)).
- **Permission resolution:** composite `acl_entries(holder_type, holder_id, scope_type, scope_id,
  permission_key)`; resolved masks are **cached** (>95% hit target), invalidated on group/ACL change.
- **Search:** baseline `FULLTEXT(posts.body_text)`; enhanced indexes to Meilisearch (the `body_text`
  projection feeds both). Switch-over threshold in [system-architecture §4](system-architecture.md).
- **Reactions/notifications:** `reactions(reactable_type, reactable_id)`; `notifications(notifiable_type,
  notifiable_id, read_at)`.
- **Unread / "what's new" at scale:** a per-user **last-read watermark** per forum + sparse per-topic reads,
  rather than a row per (user × topic) — the classic forum scaling pain, designed around from day one.
- **Append-heavy tables** (`audit_log`, `notifications`, `registration_checks`): **time-based partitioning or
  periodic archival** are documented headroom for the *large* tier (not built at MVP). `posts` can be
  range-partitioned by `id` at very large scale. The **path to large** (read replicas, Redis, CDN) is in
  [system-architecture §8](system-architecture.md) and requires **no schema rewrite** — only the conventions
  above (stateless app, queueable writes, cache-through reads, `tenant_id` seam) preserved now.

## 10. i18n / RTL / a11y data implications (ADR-0016)

- **UI strings** are externalized to language files (not the DB) — RTL handled at render via `dir`/CSS logical
  properties.
- **Admin-defined content** that needs translation (custom-field labels, prefixes, badge names) gets a
  lightweight **translations table or JSON-per-locale** column so multilingual communities aren't blocked.
- **User content** stores its language hint where known (for `lang`/`dir` on render and search analyzer
  selection). None of this is built at MVP, but the schema **does not preclude** it — the brief's requirement.

## Cross-references

Permission resolution & the inspector: [security-and-permissions](security-and-permissions.md) ·
Content rendering pipeline & sanitizer licensing: [DECISIONS.md](../../DECISIONS.md) ·
Module/theme storage vs filesystem: [plugin-and-theme-system](plugin-and-theme-system.md) ·
Performance budgets & the scale path: [system-architecture](system-architecture.md).
