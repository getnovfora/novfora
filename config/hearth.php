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
];
