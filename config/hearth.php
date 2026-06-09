<?php

// SPDX-License-Identifier: Apache-2.0

/*
|--------------------------------------------------------------------------
| Hearth — anti-spam & moderation (ADR-0007)
|--------------------------------------------------------------------------
| All tunables for the M3 anti-spam baseline. Trust-level gating is expressed as ACL entries on the
| seeded TL groups (security §2.3) — there is no parallel permission system. Every external dependency
| degrades gracefully to a local mechanism; nothing here is load-bearing on a network service.
|
| Gate values are plain strings (allow | no | never) — config:cache–safe on the baseline tier — and are
| mapped to the three-state PermissionValue by TrustGateSeeder.
*/

// Progressive capabilities granted from TL1 up. A user is in exactly ONE trust group, so each level's
// gate set is self-contained (higher levels are not unions of lower ones). Defined once, reused for tl1+.
$trusted = [
    'post.links' => 'allow',         // hard-gated at TL0 (never); granted here
    'post.images' => 'allow',
    'pm.send' => 'allow',            // PMs are a Phase 2 feature; this is the gate seam
    'attachment.create' => 'allow',  // soft gate at TL0 (no); granted here
];

return [

    // Post reactions (P2-M1). XF-style single-choice typed reactions: a user picks at most one type per post.
    // Each type carries a `score` weight that is CONFIG-ONLY AND INERT this milestone — the reputation ledger
    // that would consume it is P2-M3 (held), so reactions accrue no reputation yet (Phase-2 amendment #4).
    // Reactions are fully functional with zero reputation dependency; tune the type set freely.
    'reactions' => [
        'types' => [
            'like' => ['label' => 'Like', 'emoji' => '👍', 'score' => 1],
            'love' => ['label' => 'Love', 'emoji' => '❤️', 'score' => 1],
            'helpful' => ['label' => 'Helpful', 'emoji' => '💡', 'score' => 2],
            'insightful' => ['label' => 'Insightful', 'emoji' => '🧠', 'score' => 2],
            'funny' => ['label' => 'Funny', 'emoji' => '😄', 'score' => 0],
            'disagree' => ['label' => 'Disagree', 'emoji' => '👎', 'score' => -1],
        ],
        // Per-trust reactions/minute, enforced via the cache RateLimiter (tier-graceful, like post rates).
        'rate_limits' => [
            'tl0' => 10,
            'tl1' => 30,
            'default' => 60,
        ],
    ],

    'antispam' => [

        // Seeded as acl_entries on the trust groups (TrustGateSeeder). never = absolute hard gate an admin
        // cannot lift (the spam-vector lockdown); no = soft, admin-liftable seam; allow = granted. These are
        // the DEFAULTS — admins tune them per-forum within the never/no rules via the ACL (the inspector
        // explains any block, e.g. "tl0 group: post.links = NEVER").
        'trust_gates' => [
            'tl0' => [
                'post.links' => 'never',         // links in the first window — true spam vector
                'post.images' => 'never',        // inline images — true spam vector
                'pm.send' => 'never',            // mass-PM vector (PMs ship in Phase 2)
                'attachment.create' => 'no',     // soft: members grants it by default; admin-liftable
            ],
            'tl1' => $trusted,
            'tl2' => $trusted,
            'tl3' => $trusted,
            'tl4' => $trusted,
        ],

        // New-user moderation queue: a TL0 author's first N APPROVED posts are held (approved_state=pending)
        // for staff approval (security §2.4). Keyed on TL0 group membership, so it never catches trusted users.
        // HEARTH_NEW_USER_HOLD_POSTS is the owner's live env preference; the ACP "Moderation defaults" page
        // overrides it via the Settings store (DB → this env → default 2; ADR-0023). 0 = auto-post.
        'new_user_moderation' => [
            'posts' => (int) env('HEARTH_NEW_USER_HOLD_POSTS', 2),
        ],

        // Auto promotion/demotion (data-model §4). Numeric promotion thresholds live on each TL group's
        // `auto_promotion` (seeded by GroupSeeder); this governs the demotion side. A live infraction-point
        // total at/above this demotes the account to TL0; any live flag below it freezes promotion.
        'trust' => [
            'demotion_points' => 10,
        ],

        // Post-time content scanning heuristics (LocalHeuristicsScanner, ADR-0007 §2.4). Conservative:
        // a suspicious scan HOLDS for moderation, never hard-blocks. Operators extend suspicious_phrases.
        'content' => [
            'max_links' => 3,           // ≥ this many links in one post contributes to the suspicion score
            'suspicious_score' => 2,    // score at/above which a post is held for moderation
            'suspicious_phrases' => [], // e.g. ['cheap viagra', 'casino bonus'] — each match adds to the score
        ],

        // Per-trust post-rate limits (posts/minute), enforced via Laravel's cache-backed RateLimiter —
        // DB cache on the baseline tier, Redis on enhanced, with no code change (tier-graceful, ADR-0011).
        'rate_limits' => [
            'tl0' => 2,
            'tl1' => 8,
            'default' => 20,
        ],

        // Warnings / infractions (security §3): automated consequences when live point totals cross these.
        'warnings' => [
            'thresholds' => [
                'moderate' => 5,   // ≥ → posts held for moderation (status=pending) until acknowledged
                'temp_ban' => 15,  // ≥ → a temporary ban
                'ban' => 30,       // ≥ → a permanent ban
            ],
            'temp_ban_days' => 7,
        ],

        // Layer 1 — registration. Every control degrades to a local mechanism (security §2.2 / §2.6).
        'registration' => [
            // Per-IP rate limit on POST /register (phase-1.5 F-B). Off in the test env so M1's
            // RegistrationTest stays frictionless; the dedicated throttle test opts back in.
            'rate_limit' => [
                'enabled' => env('HEARTH_REGISTER_THROTTLE', true),
                'per_ip_per_hour' => (int) env('HEARTH_REGISTER_MAX', 10),
            ],
            'stopforumspam' => [
                'enabled' => true,
                'use_api' => env('HEARTH_SFS_API', true), // live API best-effort; degrade to the cron-cached blocklist (off in tests → no network)
                'confidence_threshold' => 75,             // ≥ this confidence → block; below → flag (flag-don't-block)
                'timeout' => 4,                           // seconds; a slow/dead API must not stall registration
                // Cron-warmed blocklist so the cache is never cold (phase-1.5 F-C). Downloads a curated
                // toxic-domains list into blocklist_cache; degrades to a no-op on any network failure.
                'warm' => [
                    'enabled' => env('HEARTH_SFS_WARM', true),
                    'domains_url' => env('HEARTH_SFS_DOMAINS_URL', 'https://www.stopforumspam.org/downloads/toxic_domains_whole.txt'),
                    'ttl_days' => 14,
                    'max_entries' => 20000, // bound the import for the baseline tier
                ],
            ],
            'captcha' => [
                // Default provider for any action; degrades to qa when unavailable (CaptchaManager).
                'provider' => env('HEARTH_CAPTCHA', 'qa'), // qa | turnstile | null
                'actions' => [
                    // Per-action overrides, e.g. 'register' => 'turnstile', 'post' => 'qa'.
                ],
                'qa' => [
                    'question' => 'What colour is a clear daytime sky? (one word)',
                    'answers' => ['blue'],
                    // Bind each challenge to a single-use server nonce so a captured answer can't be
                    // replayed (phase-1.5 F-B). Off in the test env; the dedicated replay test opts in.
                    'single_use' => env('HEARTH_QA_SINGLE_USE', true),
                ],
                'turnstile' => [ // enhanced-tier example; absent secret → manager degrades to qa
                    'site_key' => env('TURNSTILE_SITE_KEY', ''),
                    'secret' => env('TURNSTILE_SECRET', ''),
                ],
            ],
            'honeypot' => [
                'field' => 'hp_url',          // a hidden field bots fill; humans never see it
                'min_seconds' => 2,           // a form submitted faster than this is bot-like
                // Require the timing token (phase-1.5 F-B): a submission with no/garbled hp_ts is rejected,
                // closing the "just omit the token" skip. Off in the test env; the F-B test opts in.
                'required' => env('HEARTH_HONEYPOT_REQUIRED', true),
            ],
            'velocity' => [
                'per_ip_per_hour' => 5,       // local IP registration-rate ceiling → flag on spikes
            ],
            'disposable_email' => [
                'enabled' => true,            // local maintained list (blocklist_cache, source=disposable)
            ],
        ],

        // Privacy/GDPR (security §2.6): registration_checks carry PII (IP/email) and are purged after this
        // window by `hearth:antispam:purge` (run from the scheduler).
        'retention' => [
            'registration_checks_days' => 90,
        ],
    ],

    // ── Moderation (security §3) ──────────────────────────────────────────────────────────────────
    // Actor-vs-target rank check (phase-1.5 F-F): a staff member can't ban/warn/spam-clean a target of
    // equal-or-higher rank. Admins outrank everyone; mods can't action admins or (by default) each other.
    'moderation' => [
        'rank' => [
            'allow_equal' => (bool) env('HEARTH_MOD_RANK_ALLOW_EQUAL', false), // true → equal rank may act
        ],
    ],

    // Theming (ADR-0009 §3.2). The developer override layer resolves active theme → parent → core. The
    // default (active = null) is core's mobile-first views with the a11y floor. See docs/THEME-API.md.
    'theme' => [
        'active' => env('HEARTH_THEME'),        // null = built-in default (core views)
        'path' => base_path('themes'),          // filesystem location of child-theme packages
        'api_version' => '1.0',                  // the semver'd theme contract (ThemeManager::API_VERSION)
    ],

    // ── Operability — the no-SSH web installer (M5, phase-1-plan §5) ──────────────────────────────
    // The installer is an UNAUTHENTICATED pre-install surface that writes .env, runs migrations, and
    // creates the first admin. It MUST lock after install: the marker file below is written LAST and,
    // once present, the installer refuses to run again. There is no web route that removes it — the
    // only reset is a deliberate filesystem action on the host (documented in docs/getting-started.md).
    'install' => [
        // The "installed" lock marker. A plain file (not a DB flag) so the lock holds even before the
        // DB exists and survives a DB wipe. Its presence === installed (Install\Installer::isInstalled()).
        'marker' => env('HEARTH_INSTALL_MARKER', storage_path('installed')),

        // The .env target the installer writes. Overridable so tests never clobber the real file.
        'env_path' => env('HEARTH_INSTALL_ENV_PATH', base_path('.env')),

        // Pre-install setup token (phase-1.5 F-A). A random token is written here (0600) on first boot of a
        // not-yet-installed site; the wizard and `hearth:install` require it, so whoever reaches the
        // unauthenticated installer first cannot run it (or use the DB-test SSRF) without filesystem access
        // (FTP / cPanel File Manager) to read the value. Consumed on a successful install. Off in tests.
        'token_path' => env('HEARTH_INSTALL_TOKEN_PATH', storage_path('install-token.txt')),
        'require_token' => env('HEARTH_INSTALL_REQUIRE_TOKEN', true),

        // When true (the default in production), an un-installed app forces every request to the wizard
        // and the pre-install boot hook forces zero-dependency drivers (file session/cache, sync queue)
        // + an APP_KEY so a freshly-uploaded tree boots with no DB. Disabled in the test env (phpunit.xml)
        // so the 247-test suite — which provisions its own DB — never gets redirected to /install.
        'enforce' => env('HEARTH_INSTALL_ENFORCE', true),
    ],

    // ── Operability — backups & restore (M5) ─────────────────────────────────────────────────────
    // Baseline-safe: a DB dump + a storage archive + a manifest, bundled into ONE portable .zip. Runs
    // from the single cron line (ADR-0011) and from the admin UI; restore is a CLI path. Nothing here
    // hard-depends on an enhanced service.
    'backup' => [
        'path' => env('HEARTH_BACKUP_PATH', storage_path('backups')), // where archives are written
        'keep' => (int) env('HEARTH_BACKUP_KEEP', 7),                 // retain the N newest; prune older
        'schedule' => env('HEARTH_BACKUP_SCHEDULE', 'daily'),         // daily | weekly | off (scheduler)
        // How MySQL/MariaDB is dumped/restored. 'auto' shells out to mysqldump/mysql when proc_open is
        // available, else falls back to a pure-PHP dump over PDO (baseline-safe — no proc_open/exec needed).
        // 'php' forces the in-process path (use it if your host disables proc_open); 'shell' forces the
        // external tools. hearth:doctor reports which one your host will use. (SQLite always copies the file;
        // PostgreSQL uses pg_dump/psql and is an enhanced-tier database.)
        'db_method' => env('HEARTH_BACKUP_DB_METHOD', 'auto'),        // auto | php | shell

        // ── No-SSH panel restore (RH-11 / ADR-0022) ───────────────────────────────────────────────
        // A restore OVERWRITES the live DB — and on the baseline tier the cache, session, AND queue all
        // live in that DB. So the restore's maintenance state CANNOT live in the cache (it'd be wiped
        // mid-restore) and the restore CANNOT be a DB queue job (its own jobs row would vanish). The state
        // is a small JSON file + an flock lock, BOTH outside storage/app (the restore target) so they
        // survive the DB swap; the run is drained by the single cron line (App\Backup\RestoreRunner).
        'restore_state_path' => env('HEARTH_RESTORE_STATE_PATH', storage_path('hearth-restore.json')),
        'restore_lock_path' => env('HEARTH_RESTORE_LOCK_PATH', storage_path('hearth-restore.lock')),

        // Take a pre-restore SAFETY snapshot of the CURRENT state before overwriting it, so a panel restore
        // is itself reversible (created with keep=0 so it can never prune the archive being restored).
        'pre_restore_safety' => (bool) env('HEARTH_RESTORE_SAFETY_BACKUP', true),

        // A restore is destructive, so it is SINGLE-ATTEMPT and fail-safe: it either succeeds, or it HOLDS
        // the site in maintenance (stuck) — it is never auto-retried (re-running a destructive op that just
        // failed, or that was killed mid-apply, could make things worse). A run killed mid-restore is
        // detected on the next cron tick (the file lock is free yet the state still says "running") and held.
        // Recovery from a held restore: re-restore from the panel once reachable, or — the no-SSH escape —
        // delete the restore-state file (hearth.backup.restore_state_path) via the host file manager, then
        // restore a known-good backup / the named pre-restore safety snapshot.
    ],

    // ── Operability — no-SSH automatic upgrade (RH-10 / ADR-0021) ──────────────────────────────────
    // Keeps getting-started §5's promise true on the baseline tier: extract a new release over a live
    // install and the schema migrates itself via the single cron line — no SSH, behind a backup-first,
    // maintenance-safe window. The mechanism lives in App\Upgrade (SchemaState + UpgradeRunner).
    'upgrade' => [
        // The documented promise. true = the scheduler applies pending migrations automatically (and
        // gates requests behind a branded maintenance 503 during the ≤~2-minute window, so new code can
        // never 500 a signed-in page on a column the DB doesn't have yet). false = MANUAL mode: nothing
        // auto-runs; an admin applies via Admin → System → Upgrade (or `php artisan hearth:upgrade`).
        // ASYMMETRY (see docs): auto mode is what protects signed-in pages from new-column 500s — manual
        // mode does not gate the whole site (so the admin can reach the panel), so signed-in pages MAY
        // error until the operator applies. Auto is the default for exactly this reason.
        'auto' => (bool) env('HEARTH_AUTO_UPGRADE', true),

        // The upgrade run takes this cache lock so it can never double-run across overlapping cron ticks
        // (belt-and-braces with the schedule's withoutOverlapping). Long enough that a real migration
        // finishes within it; if the process is KILLED mid-run the lock auto-expires and the next tick
        // safely resumes (migrations are idempotent — already-applied ones are skipped).
        'lock_seconds' => (int) env('HEARTH_UPGRADE_LOCK_SECONDS', 600),

        // Automatic-mode failure policy: attempt at most this many times across cron ticks, then HOLD
        // for the operator (health → schema.stuck, a branded maintenance page with the recovery hint).
        // "No retry loop" — 2 = the initial attempt plus one retry. A human (admin panel / CLI) can
        // always retry past this; the cap only bounds the unattended loop.
        'max_auto_attempts' => (int) env('HEARTH_UPGRADE_MAX_ATTEMPTS', 2),

        // Retry-After (seconds) on the maintenance 503 — a hint to browsers/monitors to come back after
        // roughly one cron interval, since the window self-heals on the next tick.
        'retry_after' => (int) env('HEARTH_UPGRADE_RETRY_AFTER', 30),

        // Migration source paths used BOTH for cheap pending-detection (the code fingerprint) and for the
        // upgrade run itself, so detection and execution can never disagree. Default: the app's migrations.
        // Overridable mainly so tests can point at a fixture migration; module/theme paths (Phase 2) extend
        // this list. Keep these absolute.
        'migration_paths' => [database_path('migrations')],
    ],

    // ── Public storage publishing (avatars/covers) ───────────────────────────────────────────────
    // `php artisan hearth:storage:publish` (and the installer) make uploaded public files reachable at
    // public/storage. A real symlink is preferred; where the host forbids symlink() the files are COPIED
    // and the cron line keeps the copy refreshed. Set use_symlink=false to force the copy on a host you
    // know blocks symlinks. The paths are overridable mainly for testing.
    'storage' => [
        'use_symlink' => (bool) env('HEARTH_STORAGE_SYMLINK', true),
        'public_link' => env('HEARTH_PUBLIC_LINK', public_path('storage')),
        'public_source' => env('HEARTH_PUBLIC_SOURCE', storage_path('app/public')),
    ],

    // ── Security response headers (security §4: "strict CSP" + clickjacking/MIME hardening) ────────
    // Emitted on every web response by App\Http\Middleware\SecurityHeaders. The default CSP is the
    // NON-BREAKING baseline: it locks down the high-value sinks (object/base/frame/form) but keeps
    // script/style permissive ('unsafe-inline'/'unsafe-eval') because Livewire + Alpine + the inline-
    // styled core views + JSON-LD currently need them. A STRICT nonce-based CSP (script-src 'self'
    // 'nonce-…', no unsafe-*) is the documented follow-up in docs/SECURITY-REVIEW.md — it needs Livewire
    // nonce config + the Alpine CSP build + moving inline styles to classes, so it is owner-gated.
    // Everything here is overridable via env so an operator can relax/replace the policy without a code
    // change.
    'security' => [
        'headers' => [
            'enabled' => (bool) env('HEARTH_SECURITY_HEADERS', true),
            // HSTS is emitted ONLY on https requests (browsers ignore it over plain http, and sending it
            // could strand a non-TLS baseline host). 0 disables it.
            'hsts_max_age' => (int) env('HEARTH_HSTS_MAX_AGE', 15552000), // 180 days
        ],
        'csp' => [
            'enabled' => (bool) env('HEARTH_CSP', true),
            'policy' => env('HEARTH_CSP_POLICY', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "media-src 'self' https:",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'self'",
            ])),

            // ── Strict, nonce-based CSP (phase-1.5 F-M3) — OPT-IN ──────────────────────────────────
            // When HEARTH_CSP_STRICT=true the middleware emits this policy instead, replacing {nonce}
            // per-request. @vite and Livewire pick up the SAME nonce automatically via Vite::cspNonce(),
            // and Hearth's two inline <script> blocks (JSON-LD, Turnstile loader) carry it too — so
            // script-src drops 'unsafe-inline' (inline-script injection is blocked). It still keeps
            // 'unsafe-eval' (Alpine v3 evaluates expressions with new Function) and style 'unsafe-inline'
            // (the core views use inline style="" attributes, which nonces don't cover). Making it the
            // DEFAULT needs the Alpine CSP build + an inline-style/style-attr refactor — tracked in
            // docs/SECURITY-REVIEW.md (F-M3). Default OFF so the shipped baseline keeps the editor working.
            'strict' => (bool) env('HEARTH_CSP_STRICT', false),
            'strict_policy' => env('HEARTH_CSP_STRICT_POLICY', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{nonce}' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "media-src 'self' https:",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'self'",
            ])),
        ],
    ],

    // ── Deliverability — digest + bounce/suppression (Spike P2, Phase-2 plan §4) ────────────────────
    // REFERENCE PIPELINE, DORMANT BY DEFAULT. This is the de-risk spike for P2-M2's digest/bounce work:
    // a cron-only digest batcher + a daemon-free, tri-path bounce/complaint → suppression pipeline that
    // never burns the host's sending reputation. NOTHING here touches the live immediate notification
    // path (App\Notifications\Notifier) — every consumer is gated on `enabled` below and ships OFF, so a
    // deploy changes no behaviour until P2-M2 (or an operator) flips the flag. See docs/product/spike-p2-memo.md.
    'deliverability' => [
        // Master switch. While false: the digest + bounce-poll cron lines do not even register, the
        // webhook + unsubscribe routes are inert, and the assembler is a no-op. P2-M2 turns this on.
        'enabled' => (bool) env('HEARTH_DELIVERABILITY', false),

        // (a) CRON-BATCHED DIGEST. Coalesces a user's pending notifications into ONE email per cadence,
        // idempotent across coarse/overlapping/killed cron ticks (the M5 queue-drain discipline). The
        // guarantee rests on a committed UNIQUE(user_id,cadence,period_key) row, NOT on the lock.
        'digest' => [
            'enabled' => (bool) env('HEARTH_DIGEST', false),
            // Per-tick send cap (volume hygiene): at most this many users' digests are assembled per tick,
            // so a large backlog drains over later ticks instead of bursting the host's mail quota.
            'max_users_per_tick' => (int) env('HEARTH_DIGEST_USERS_PER_TICK', 50),
            // Per-user item cap: one digest carries at most this many items; the overflow rolls into the
            // next period. Bounds a single email and the per-user send rate.
            'per_user_item_rate' => (int) env('HEARTH_DIGEST_ITEM_RATE', 100),
            // Overlap mutex (minutes) for the assembler tick. SHORT and bounded (NOT Laravel's 24h default)
            // so a SIGKILLed run — which releases no handler — can't strand the digest for a day; the DB
            // UNIQUE row is the real double-run guard (RH-10 discipline). Kept < 60 (see SchedulerTest).
            'mutex_minutes' => max(2, (int) env('HEARTH_DIGEST_MUTEX_MIN', 2)),
        ],

        // (b) DAEMON-FREE BOUNCE/COMPLAINT INGESTION — detect + degrade across three paths. A hard bounce
        // (SMTP 5.x.x) or a complaint auto-suppresses the address (into email_suppressions); transient
        // 4.x.x is NEVER suppressed. With nothing configured the manual-ACP + VERP/Return-Path floor still
        // works — ingestion never throws (forced-absence). The recommended OUTSIDER-email path is a
        // transactional provider (Postmark/SES/Mailgun) with an on-domain From + SPF/DKIM/DMARC.
        'webhook' => [
            // Provider webhook endpoint (POST /webhooks/mail/{provider}). Registered only when enabled AND
            // a secret is set. Trust is cryptographic (HMAC over the RAW body), never reachability.
            'enabled' => (bool) env('HEARTH_MAIL_WEBHOOK', false),
            'secret' => (string) env('HEARTH_MAIL_WEBHOOK_SECRET', ''),
            'tolerance_seconds' => (int) env('HEARTH_MAIL_WEBHOOK_TOLERANCE', 300), // replay window
            'max_body_bytes' => (int) env('HEARTH_MAIL_WEBHOOK_MAX_BYTES', 262144), // 256 KB hard cap
        ],
        // VERP / signed Return-Path: the recipient is embedded in the envelope sender so a bounce identifies
        // the address with no body parsing. The local-part carries an HMAC so a FORGED bounce can't suppress
        // a victim. Distinct from the on-domain From (which must stay on-domain for SPF/DKIM alignment).
        'verp' => [
            'enabled' => (bool) env('HEARTH_VERP', false),
            'domain' => (string) env('HEARTH_VERP_DOMAIN', ''),      // e.g. bounce.example.com
            'key' => (string) env('HEARTH_VERP_KEY', ''),            // HMAC key; empty → VERP disabled
        ],
        // Cron-polled IMAP bounce mailbox. Registered only when enabled; the reader is guarded by
        // extension_loaded('imap') and degrades to a no-op (NullBounceMailbox) when the ext is absent.
        'imap' => [
            'enabled' => (bool) env('HEARTH_BOUNCE_IMAP', false),
            'host' => (string) env('HEARTH_BOUNCE_IMAP_HOST', ''),
            'port' => (int) env('HEARTH_BOUNCE_IMAP_PORT', 993),
            'encryption' => (string) env('HEARTH_BOUNCE_IMAP_ENCRYPTION', 'ssl'), // ssl | tls | none
            'username' => (string) env('HEARTH_BOUNCE_IMAP_USER', ''),
            'password' => (string) env('HEARTH_BOUNCE_IMAP_PASS', ''),
            'mailbox' => (string) env('HEARTH_BOUNCE_IMAP_MAILBOX', 'INBOX'),
            'per_tick_cap' => (int) env('HEARTH_BOUNCE_BATCH', 100), // bounded fetch per cron tick
            'delete_processed' => (bool) env('HEARTH_BOUNCE_IMAP_DELETE', true),
        ],
    ],
];
