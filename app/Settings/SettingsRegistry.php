<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Settings;

/**
 * The authoritative catalogue of every site setting the ACP can manage (ACP v1, PART 0). Each entry
 * declares the setting's type, whether it is a secret, the config() path its fallback resolves through
 * (config files already fold env() → hardcoded default, so a single config read realises the documented
 * "env fallback → config default" tail of the precedence; ADR-0023), and a literal default for pure-DB
 * settings with no config backing. The registry is also the index the ACP quick-search builds over —
 * the `label`/`group` here are the searchable text.
 *
 * Adding a setting = adding one entry here; the Settings service, the seeded defaults, the precedence,
 * and the audit masking all flow from it. Nothing else hard-codes a setting key's type or default.
 */
final class SettingsRegistry
{
    /** @var array<string,SettingDefinition>|null */
    private static ?array $cache = null;

    /**
     * Module-registered setting definitions (Phase-3 hardening, D3 contract gap). A plugin declaring
     * `provides: ["settings"]` calls {@see register()} from its provider so it can read/write its own keys
     * through the same typed Settings service the ACP uses — the settings seam was declared but had no
     * registration path before. Keyed by setting key (idempotent).
     *
     * @var array<string,SettingDefinition>
     */
    private static array $runtime = [];

    /**
     * Register a module-owned setting definition. A module may NOT override a CORE key (core wins in all()),
     * so a plugin can never hijack e.g. mail.password. Idempotent; invalidates the built cache.
     */
    public static function register(SettingDefinition $definition): void
    {
        self::$runtime[$definition->key] = $definition;
        self::$cache = null;
    }

    /** Drop module-registered definitions (test isolation; static state survives an app rebuild). */
    public static function flushRuntime(): void
    {
        self::$runtime = [];
        self::$cache = null;
    }

    /** @return array<string,SettingDefinition> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $defs = [];
        foreach (self::definitions() as $def) {
            $defs[$def->key] = $def;
        }
        // Module-registered keys fill gaps only — a core key is never overridden by a plugin.
        foreach (self::$runtime as $key => $def) {
            $defs[$key] ??= $def;
        }

        return self::$cache = $defs;
    }

    public static function get(string $key): ?SettingDefinition
    {
        return self::all()[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return list<SettingDefinition> */
    private static function definitions(): array
    {
        return [
            // ── General (PART 3.1) ──────────────────────────────────────────────────────────────────
            new SettingDefinition('general.site_name', 'string', config: 'app.name', default: 'NovFora', group: 'general', label: 'Site name'),
            new SettingDefinition('general.site_description', 'string', default: '', group: 'general', label: 'Site description / tagline'),
            new SettingDefinition('general.site_notice', 'string', default: '', group: 'general', label: 'Site-wide notice'),
            new SettingDefinition('general.board_offline', 'bool', default: false, group: 'general', label: 'Take the board offline'),
            new SettingDefinition('general.board_offline_message', 'string', default: 'The board is temporarily offline for maintenance. Please check back soon.', group: 'general', label: 'Board-offline message'),

            // ── Registration (PART 3.2) ─────────────────────────────────────────────────────────────
            new SettingDefinition('registration.enabled', 'bool', default: true, group: 'registration', label: 'Allow new registrations'),
            // No config backing: the toggle drives email_verified_at at registration (CreateNewUser), the
            // existing mechanism. Default true = new users must verify (current behaviour).
            new SettingDefinition('registration.require_email_verification', 'bool', default: true, group: 'registration', label: 'Require email verification'),

            // ── Email (PART 3.3) ────────────────────────────────────────────────────────────────────
            new SettingDefinition('mail.mailer', 'string', config: 'mail.default', default: 'log', group: 'email', label: 'Mailer', options: ['log', 'smtp', 'sendmail', 'array']),
            new SettingDefinition('mail.from_name', 'string', config: 'mail.from.name', default: 'NovFora', group: 'email', label: 'From name'),
            new SettingDefinition('mail.from_address', 'string', config: 'mail.from.address', default: 'hello@example.com', group: 'email', label: 'From address'),
            new SettingDefinition('mail.host', 'string', config: 'mail.mailers.smtp.host', default: '127.0.0.1', group: 'email', label: 'SMTP host'),
            new SettingDefinition('mail.port', 'int', config: 'mail.mailers.smtp.port', default: 587, group: 'email', label: 'SMTP port'),
            new SettingDefinition('mail.username', 'string', config: 'mail.mailers.smtp.username', default: '', group: 'email', label: 'SMTP username'),
            new SettingDefinition('mail.password', 'string', encrypted: true, config: 'mail.mailers.smtp.password', default: '', group: 'email', label: 'SMTP password'),
            new SettingDefinition('mail.scheme', 'string', config: 'mail.mailers.smtp.scheme', default: '', group: 'email', label: 'SMTP encryption', options: ['', 'smtps']),

            // ── Moderation defaults (PART 3.4) ──────────────────────────────────────────────────────
            // new_user_hold_posts honours the owner's NOVFORA_NEW_USER_HOLD_POSTS env via the config path
            // (wired in config/novfora.php), so an unset DB row tracks env; a panel value overrides + persists.
            new SettingDefinition('moderation.new_user_hold_posts', 'int', config: 'novfora.antispam.new_user_moderation.posts', default: 2, group: 'moderation', label: 'New-user first-post hold count'),
            new SettingDefinition('moderation.suspicious_score', 'int', config: 'novfora.antispam.content.suspicious_score', default: 2, group: 'moderation', label: 'Suspicious-score hold threshold'),
            new SettingDefinition('moderation.max_links', 'int', config: 'novfora.antispam.content.max_links', default: 3, group: 'moderation', label: 'Links per post before suspicion'),
            new SettingDefinition('moderation.rate_tl0', 'int', config: 'novfora.antispam.rate_limits.tl0', default: 2, group: 'moderation', label: 'Flood limit — new users (posts/min)'),
            new SettingDefinition('moderation.rate_tl1', 'int', config: 'novfora.antispam.rate_limits.tl1', default: 8, group: 'moderation', label: 'Flood limit — TL1 (posts/min)'),
            new SettingDefinition('moderation.rate_default', 'int', config: 'novfora.antispam.rate_limits.default', default: 20, group: 'moderation', label: 'Flood limit — established (posts/min)'),

            // ── Anti-spam (PART 3.5) ────────────────────────────────────────────────────────────────
            new SettingDefinition('antispam.captcha_provider', 'string', config: 'novfora.antispam.registration.captcha.provider', default: 'qa', group: 'antispam', label: 'CAPTCHA provider', options: ['qa', 'turnstile', 'none']),
            new SettingDefinition('antispam.turnstile_site_key', 'string', config: 'novfora.antispam.registration.captcha.turnstile.site_key', default: '', group: 'antispam', label: 'Turnstile site key'),
            new SettingDefinition('antispam.turnstile_secret', 'string', encrypted: true, config: 'novfora.antispam.registration.captcha.turnstile.secret', default: '', group: 'antispam', label: 'Turnstile secret key'),
            new SettingDefinition('antispam.sfs_use_api', 'bool', config: 'novfora.antispam.registration.stopforumspam.use_api', default: true, group: 'antispam', label: 'StopForumSpam live API'),

            // ── Appearance, site-level (PART 3.6) ───────────────────────────────────────────────────
            new SettingDefinition('appearance.active_theme', 'string', config: 'novfora.theme.active', default: '', group: 'appearance', label: 'Active theme'),
            new SettingDefinition('appearance.accent_color', 'string', default: '', group: 'appearance', label: 'Accent colour'),
            new SettingDefinition('appearance.forum_width', 'string', default: 'standard', group: 'appearance', label: 'Forum width', options: ['boxed-narrow', 'standard', 'wide', 'full']),
            new SettingDefinition('appearance.default_color_mode', 'string', default: 'auto', group: 'appearance', label: 'Default colour mode (visitors)', options: ['auto', 'light', 'dark']),
            new SettingDefinition('appearance.default_density', 'string', default: 'comfortable', group: 'appearance', label: 'Default density (visitors)', options: ['comfortable', 'compact']),
            new SettingDefinition('appearance.poster_position', 'string', default: 'left', group: 'appearance', label: 'Poster-info position', options: ['top', 'left', 'right']),
            new SettingDefinition('appearance.board_list_style', 'string', default: 'info-rich', group: 'appearance', label: 'Board-list style', options: ['info-rich', 'minimal']),
            new SettingDefinition('appearance.wordmark', 'string', default: '', group: 'appearance', label: 'Wordmark text'),

            // ── Members directory (public listing) ──────────────────────────────────────────────────
            // Gates the public /members directory. 'everyone' (incl. guests) → 'members' (signed-in) →
            // 'staff' → 'disabled' (off). Read by App\Community\MembersDirectory::visibleTo().
            new SettingDefinition('members.directory_visibility', 'string', default: 'everyone', group: 'members', label: 'Members directory visibility', options: ['disabled', 'staff', 'members', 'everyone']),

            // ── Search engine (Phase 4 · M4.1) ──────────────────────────────────────────────────────
            // The Scout driver + Meilisearch connection. `database` is the baseline (no service); switching
            // to `meilisearch` is an OPT-IN enhanced upgrade — the ACP refuses the switch unless the host is
            // reachable, and the runtime degrades to `database` automatically if it later becomes unreachable.
            // The key is stored ENCRYPTED. These push into scout.driver / scout.meilisearch.* at boot.
            new SettingDefinition('search.driver', 'string', config: 'scout.driver', default: 'database', group: 'search', label: 'Search driver', options: ['database', 'meilisearch']),
            new SettingDefinition('search.meilisearch_host', 'string', config: 'scout.meilisearch.host', default: 'http://localhost:7700', group: 'search', label: 'Meilisearch host'),
            new SettingDefinition('search.meilisearch_key', 'string', encrypted: true, config: 'scout.meilisearch.key', default: '', group: 'search', label: 'Meilisearch API key'),

            // ── Clubs (Phase 4 · M1.6) ──────────────────────────────────────────────────────────────
            // Who may create a club. 'any' = any verified member; 'trust' = a verified member at trust level
            // ≥ clubs.creation_min_trust_level (default 2); 'staff' = administrators & moderators only (the
            // conservative realisation of "admin-approved" — a request→approval queue is deferred). Staff may
            // always create regardless. Read by App\Clubs\ClubCreation.
            new SettingDefinition('clubs.creation_policy', 'string', default: 'trust', group: 'clubs', label: 'Who can create clubs', options: ['any', 'trust', 'staff']),
            new SettingDefinition('clubs.creation_min_trust_level', 'int', default: 2, group: 'clubs', label: 'Minimum trust level to create a club'),

            // ── SSO / social login (Phase 4 · M2) ───────────────────────────────────────────────────
            // Per-provider OFF by default; secrets stored ENCRYPTED at rest. The password login path is
            // unaffected. Read by App\Auth\Social\SocialProviders (which configures the Socialite driver
            // from these at request time — no env required).
            new SettingDefinition('oauth.google.enabled', 'bool', default: false, group: 'sso', label: 'Google login enabled'),
            new SettingDefinition('oauth.google.client_id', 'string', default: '', group: 'sso', label: 'Google client ID'),
            new SettingDefinition('oauth.google.client_secret', 'string', encrypted: true, default: '', group: 'sso', label: 'Google client secret'),
            new SettingDefinition('oauth.github.enabled', 'bool', default: false, group: 'sso', label: 'GitHub login enabled'),
            new SettingDefinition('oauth.github.client_id', 'string', default: '', group: 'sso', label: 'GitHub client ID'),
            new SettingDefinition('oauth.github.client_secret', 'string', encrypted: true, default: '', group: 'sso', label: 'GitHub client secret'),
            new SettingDefinition('oauth.discord.enabled', 'bool', default: false, group: 'sso', label: 'Discord login enabled'),
            new SettingDefinition('oauth.discord.client_id', 'string', default: '', group: 'sso', label: 'Discord client ID'),
            new SettingDefinition('oauth.discord.client_secret', 'string', encrypted: true, default: '', group: 'sso', label: 'Discord client secret'),

            // ── SAML SSO (Phase 4 · M2.4 — SCAFFOLD, not validated against a real IdP) ─────────────────
            // Inert unless `auth.saml.enabled` is on AND a concrete SamlProvider is bound (none ships). These
            // fields are the IdP metadata a real provider implementation would read. See ADR-0056.
            new SettingDefinition('auth.saml.enabled', 'bool', default: false, group: 'saml', label: 'SAML SSO enabled (requires a bound provider)'),
            new SettingDefinition('auth.saml.idp_entity_id', 'string', default: '', group: 'saml', label: 'IdP entity ID'),
            new SettingDefinition('auth.saml.idp_sso_url', 'string', default: '', group: 'saml', label: 'IdP single-sign-on URL'),
            new SettingDefinition('auth.saml.idp_x509_cert', 'string', default: '', group: 'saml', label: 'IdP signing certificate (X.509)'),
            new SettingDefinition('auth.saml.sp_entity_id', 'string', default: '', group: 'saml', label: 'Service-provider entity ID'),

            // ── Web Push (Phase 4 · M3.2) ───────────────────────────────────────────────────────────
            // VAPID keypair for Web Push. Generate with `php artisan novfora:push:vapid`. The public key is
            // served to the browser to subscribe; the private key signs the push JWT (stored encrypted). Push
            // is an OPT-IN extra channel — it does nothing until a user subscribes a device AND keys are set.
            new SettingDefinition('push.vapid_public_key', 'string', default: '', group: 'push', label: 'VAPID public key'),
            new SettingDefinition('push.vapid_private_key', 'string', encrypted: true, default: '', group: 'push', label: 'VAPID private key'),
            new SettingDefinition('push.vapid_subject', 'string', default: '', group: 'push', label: 'VAPID subject (mailto: or site URL)'),
        ];
    }
}
