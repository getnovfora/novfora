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
        'new_user_moderation' => [
            'posts' => 2,
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
            'stopforumspam' => [
                'enabled' => true,
                'use_api' => env('HEARTH_SFS_API', true), // live API best-effort; degrade to the cron-cached blocklist (off in tests → no network)
                'confidence_threshold' => 75,             // ≥ this confidence → block; below → flag (flag-don't-block)
                'timeout' => 4,                           // seconds; a slow/dead API must not stall registration
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
                ],
                'turnstile' => [ // enhanced-tier example; absent secret → manager degrades to qa
                    'site_key' => env('TURNSTILE_SITE_KEY', ''),
                    'secret' => env('TURNSTILE_SECRET', ''),
                ],
            ],
            'honeypot' => [
                'field' => 'hp_url',          // a hidden field bots fill; humans never see it
                'min_seconds' => 2,           // a form submitted faster than this is bot-like
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
        ],
    ],
];
