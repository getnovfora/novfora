<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# ACP feature taxonomy — six reference platforms (clean-room research)

> Compiled exclusively from **public official documentation** (phpBB.com admin guide/KB, SMF Online Manual,
> docs.mybb.com, ProBoards Help/admin guide, invisioncommunity.com guides, docs.xenforo.com) and official
> feature pages. **No source code, templates, or repositories were consulted** (clean-room rule). Items not
> re-verifiable in docs are marked **(unv.)**. Purpose: benchmark Hearth's ACP **coverage** (v1 → Phase 2 →
> Phase 3/4) — the design stays entirely ours.

**ACP shape at a glance**
- **phpBB 3.3** — "ACP", 8 tabs: General, Forums, Posting, Users and Groups, Permissions, Customise, Maintenance, System; per-tab left sidebar.
- **SMF 2.1** — "Administration Center": Main / Configuration / Forum / Members / Maintenance; admin-area search box.
- **MyBB 1.8** — "Admin CP" tabs: Home, Configuration, Forums & Posts, Users & Groups, Templates & Style, Tools & Maintenance.
- **ProBoards** — hosted admin panel: Dashboard; Setup → Forum / Members / Moderation; Upgrades (Ad-Free / Power-Ups).
- **Invision Community** — "AdminCP": widget dashboard; reorderable sections (System, Members, Customization, per-app areas).
- **XenForo 2** — "Admin CP": header + expandable vertical nav; organized as Users, Access privileges, Forums, Content, Appearance, Communication, Configuration, Importing, Maintenance.

## 1. Dashboard / overview
- phpBB: ACP index w/ board statistics (unv. details); "Check for updates" under System.
- SMF: vendor news feed; Support Information w/ version check + per-file version diagnostics; admin quick-search (settings, members, manual).
- MyBB: Home dashboard w/ version notification + project news (unv. specifics).
- ProBoards: Dashboard landing (contents unv.).
- Invision: customizable widget dashboard; Background Processes queue monitor; ACP notification bell w/ per-admin prefs; ACP-wide quick search; first-login Quick Setup wizard.
- XenForo: CP home w/ navigation search (unv. dashboard contents); update notifications (unv.).

## 2. Forum structure management
- phpBB: Manage forums — nested categories/forums, reorder; link/redirect forum types; per-forum passwords; copy-permissions at creation (unv. detail).
- SMF: Boards and Categories — categories, boards, child boards, per-board membergroup access; redirection boards (unv.); mass Move Topics tool.
- MyBB: forum/category CRUD, ordering, per-forum settings + permission overrides; per-forum moderation queue (unv. detail).
- ProBoards: Categories & Boards — create/reorder; per-board permissions per group; sub-boards (unv.).
- Invision: categories + forums w/ per-forum permission matrix; Q&A and redirect forum types (unv.); Pages app for custom sections.
- XenForo: node tree w/ 4 node types — category, forum, **page (arbitrary HTML)**, link/redirect; nested arbitrarily; per-node permissions.

## 3. General site settings
- phpBB: Board settings (name/description, default language/timezone/style, date format); board disable + offline message; cookie settings; no SEO-URL settings in core.
- SMF: Features and Options + Server settings (maintenance mode, basics); Languages manager.
- MyBB: Settings groups (General, date/time, board on/off w/ message); documented SEF-URL setup.
- ProBoards: Forum Settings (General; Login & Registration); maintenance toggle; hosted → no server-level settings.
- Invision: General Configuration (community name, address, copyright, update emails); online/offline switch (unv. location).
- XenForo: Options groups (board title/meta, active toggle — unv. detail); **route filters** (rewrite public URLs from ACP); **PWA configuration in core**.

## 4. Appearance / themes
- phpBB: Customise → Styles — install/activate/uninstall, set default, per-style user counts, **live preview via URL param**; no in-ACP template editor in 3.x (unv.).
- SMF: Current Theme settings + Themes and Layout (install, member theme choice, per-board theme — unv.); Smileys & Message Icons sets.
- MyBB: full **in-ACP template and stylesheet editing**; themes; Change Logo doc.
- ProBoards: visual theme editor (colors/CSS); **Layout Templates** — HTML-level layout editing w/ variables, if/else logic, live preview; community Template Library.
- Invision: Themes (easy color/logo modes; v5 markets a no-code visual Theme Editor); Languages UI; front-end menu manager.
- XenForo: Styles w/ style-property UI + in-ACP template edits (unv. detail); phrase editor; **Navigation manager**; **Widget system**; BB code button manager.

## 5. Members management
- phpBB: user admin; prune/inactive users; groups (+ leaders); ranks; custom profile fields; ban by name/IP/email; auto "Newly Registered Users" group w/ post-count limits.
- SMF: Members (search/edit; awaiting activation/approval); Membergroups (regular + post-count); admin-register users; registration agreement; Warnings; Ban list; Remove Inactive Members; reattribute guest posts.
- MyBB: search/edit; banning; groups w/ **Group Promotions** engine; User Titles ladder; custom profile fields (unv.); Awaiting Activation queue (unv.).
- ProBoards: member settings; groups w/ "powers"; staff assignment; **Pending Registrations** approve/deny; **Invite Users**; warnings; member-data export (unv. in docs).
- Invision: member search/edit ("most used feature"); groups; social sign-in; **admin restriction profiles**; group promotion rules (unv.).
- XenForo: rich user search (incl. IP) w/ **CSV export**; **batch update users**; custom fields; trophies; username-change workflow; warnings; bans; group promotions; per-user XML data portability.

## 6. Permissions UX
- phpBB: deepest model — 5 permission types; **permission roles** (reusable templates); YES/NO/NEVER tri-state masks; per-forum user/group assignment; view/trace tooling (unv.).
- SMF: membergroup permissions + per-board **permission profiles**; post-count inheritance (unv.).
- MyBB: group permissions + per-forum override grid; separate Admin Permissions for ACP areas.
- ProBoards: simple per-board dropdowns per group; "powers" model — no masks/inheritance UI.
- Invision: per-group, per-app/node matrix; **admin restrictions** (scope which ACP areas each admin sees).
- XenForo: cumulative user-group permissions + node permissions; permission analyzer per user/content (unv.).

## 7. Moderation & content tools
- phpBB: BBCodes, smilies, word censors, report/denial reasons (unv.); attachment extension groups + quotas; flood/edit-time limits (unv. detail).
- SMF: Posts and Topics settings (censored words, post settings); **Warnings** config section; Attachments and Avatars (storage, size); Calendar admin in core.
- MyBB: moderation queues (posts/threads/attachments) (unv. location); word filters; warnings; attachment types/quotas (unv.).
- ProBoards: Moderation options; warning system w/ profile warning bar; Moderate menus tied to powers; censored words (unv.).
- Invision: moderator setup, report center, content review guides; word/link filters (unv. location); warning points/actions (unv.).
- XenForo: censoring UI; spam cleaner; warnings; **terms & rules + help-page management** as first-class sections.

## 8. Anti-spam & registration
- phpBB: registration modes incl. admin/email activation (unv.); **Spambot countermeasures** w/ pluggable CAPTCHAs: GD image, reCAPTCHA, per-language **Q&A captcha**.
- SMF: dedicated **Anti-Spam** section: visual verification w/ complexity levels, reCAPTCHA, multi-answer per-language **verification questions**, guest search/post verification, PM rate limits; registration method (immediate/email/approval/disabled).
- MyBB: Security Questions, Minimum Registration Time, Hidden CAPTCHA honeypot, CAPTCHA choices (GD, reCAPTCHA/v3, hCaptcha), **Stop Forum Spam integration**, **Purge Spammer** one-click cleanup.
- ProBoards: Restrict Registration (staff approval), Guests Must Login, registration disable + invite-only; platform-level spam protection (hosted, unv.).
- Invision: **IPS Spam Defense** (reputation service), geolocation registration holds, email verification rules; v5 markets AI spam detection.
- XenForo: dedicated Spam management (approval/rejection heuristics, StopForumSpam-style checks (unv.)); spam cleaner; CAPTCHA incl. Q&A + third-party (unv.).

## 9. Email & notifications
- phpBB: email/SMTP settings; **Mass e-mail** to all/group/list, BCC batching, optional queueing.
- SMF: **Mail** section — view/flush the mail queue + SMTP settings; newsletters (mass sender).
- MyBB: mail settings; **Mass Mail deliverable as email or PM** via task queue; System Mail Log (failed sends per address+error).
- ProBoards: hosted — **no SMTP/mail UI** (notable absence).
- Invision: email setup (SMTP/API, unv.); **Bulk email** as monitored background process; admin notification emails.
- XenForo: email incl. SMTP (unv.); direct contact tools; **Activity summary** re-engagement digest; bounce processing + bounce log (unv.).

## 10. Maintenance & tools
- phpBB: DB backup (table selection) + **in-ACP restore**; **search index management** per backend; prune forums/users.
- SMF: Forum Maintenance hub — **file-version check vs current release**, find-and-repair DB errors, recount totals, empty logs/cache, optimize tables, DB backup, remove old posts/inactive members; **Scheduled Tasks manager**.
- MyBB: System Health, Cache Manager, **Recount and Rebuild**, Optimize DB, DB Backups (table/gzip/structure options), PHP Info; cron-style **Task Manager** w/ logging + Weekly Backup task.
- ProBoards: hosted — no DB/backup/maintenance tools; **no self-serve full-forum export** (recurring complaint).
- Invision: Background Processes queue (rebuilds, indexing, bulk ops); support/diagnostics tool (unv.).
- XenForo: **rebuild caches**, logs, **checks & tests** (file integrity); batch update threads/users.

## 11. Logs & auditing
- phpBB: admin log, moderator log, user notes, error log (unv. enumeration).
- SMF: dedicated Logs section (admin, moderation, errors, bans, tasks, spiders — unv. full list) + Reports.
- MyBB: Administrator / Moderator / User Email / System Mail / User Warning / task logs — all documented.
- ProBoards: staff action history (unv.).
- Invision: error logging via ACP notifications; mod/admin logs (unv.).
- XenForo: server error, moderator, admin, spam, payment, email logs (unv. enumeration).

## 12. Plugins / extensions management
- phpBB: **Extensions manager** — enable/disable/purge, version info; phpbb.com extensions DB (no in-ACP marketplace).
- SMF: **Package Manager** — install/uninstall mod packages; Modification Settings.
- MyBB: Plugins page (install/activate/deactivate); Extend site.
- ProBoards: Plugins w/ library install + **build-your-own tooling**; **per-plugin permissions** by group/rank + per-theme assignment.
- Invision: Applications & Plugins; in-ACP Marketplace existed 4.5–4.7, discontinued 2023 (manual installs now).
- XenForo: Add-ons — upload, enable/disable (incl. "disable all"), uninstall; optional config-gated **in-ACP zip installer**.

## 13. Import/export & upgrades
- phpBB: bundled converter framework (unv. list); in-ACP update check + package updater.
- SMF: upgrade packages; converters via simplemachines.org (outside ACP).
- MyBB: official **Merge System** (separate download; phpBB/SMF/vBulletin etc.); version notification.
- ProBoards: **no self-serve importers or exporters** (hosted lock-in).
- Invision: "Migrating From Another Platform" — official converters, top-level guide category.
- XenForo: full **Importing** manual — official importers, browser or CLI, post-import rebuilds, **redirection scripts preserving old URLs**.

## 14. Analytics / statistics
- phpBB: board statistics on ACP index (unv.); no charting suite.
- SMF: **Reports** generator (structure, permissions, staff); **spider/crawler tracking** w/ stats + logs (distinctive).
- MyBB: Statistics and Logging area.
- ProBoards: minimal (unv.).
- Invision: statistics/activity reporting area (marketed suite; unv. extent).
- XenForo: built-in statistics graphs (unv. location); custom member-statistics blocks.

## 15. Monetization / advanced
- phpBB: none in core. · SMF: **paid subscriptions in core** (membergroup-tied; PayPal). · MyBB: none in core.
- ProBoards: vendor upsells (Ad-Free, Power-Ups). · Invision: **Commerce app** (subscriptions, storefront, PayPal/Stripe).
- XenForo: **paid user upgrades in core** + payment profiles; **core advertising position manager**.

---

## Cross-cutting findings

**(a) Table stakes (all six):** board CRUD w/ ordering + per-forum/group permissions · group-based permission model · member search/edit + ban + staff assignment · registration gating + anti-bot challenge · theme selection · a warning/discipline mechanism · staff-action logging.

**(b) Most-praised per platform:** phpBB — permission roles/granularity, mass email · SMF — "everything in core" · MyBB — task scheduler, in-ACP template editing, pragmatic anti-spam · ProBoards — zero-maintenance + layout templates + per-plugin permissions · Invision — ACP ergonomics (widget dashboard, notifications, background-process visibility, ACP search), Commerce · XenForo — batch tooling, importers w/ URL redirection, node flexibility, core ads/upgrades.

**(c) Distinctive one-platform ideas:** SMF's file-version diagnostics + admin search · phpBB's style live-preview-by-URL + per-language Q&A captcha · MyBB's mass-mail-as-PMs · ProBoards' if/else layout templating in a visual editor · Invision's per-admin reorderable ACP + queue monitor · XenForo's route filters, PWA config, page nodes, activity-summary digest, per-user XML portability.

**Design lesson:** the hosted platform (ProBoards) wins on "nothing to maintain" but pays with no export, no mail control, no DB tools — the self-hosted five all treat backup/maintenance/logs as core ACP surface. Hearth already leads here (self-upgrade, panel backup/restore, health) — the ACP's job is to *surface* that operational strength, not hide it.

## Hearth mapping
- **ACP v1:** shell + dashboard w/ pending actions · forum structure manager · settings pages (general/site notice, registration, email + test send, moderation defaults, anti-spam) · appearance settings (theme select, accent, width, density/mode defaults, poster position, board-list style) · System panels migrated in · audit log viewer · ACP quick search · scheduled-task visibility.
- **Phase 2:** mass email/newsletter · member pruning/inactive cleanup · group promotions UI · ranks/titles · registration agreement editor · per-forum moderation overrides · registration approval/invite modes.
- **Phase 3:** extensions manager UI · theme/layout configurator · importers UI w/ redirect maps · analytics dashboards · permission roles/templates + "test as user" tracer.
- **Phase 4:** paid subscriptions/upgrades UI · advertising positions · PWA settings.
