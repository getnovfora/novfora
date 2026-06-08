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
            new SettingDefinition('general.site_name', 'string', config: 'app.name', default: 'Hearth', group: 'general', label: 'Site name'),
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
            new SettingDefinition('mail.from_name', 'string', config: 'mail.from.name', default: 'Hearth', group: 'email', label: 'From name'),
            new SettingDefinition('mail.from_address', 'string', config: 'mail.from.address', default: 'hello@example.com', group: 'email', label: 'From address'),
            new SettingDefinition('mail.host', 'string', config: 'mail.mailers.smtp.host', default: '127.0.0.1', group: 'email', label: 'SMTP host'),
            new SettingDefinition('mail.port', 'int', config: 'mail.mailers.smtp.port', default: 587, group: 'email', label: 'SMTP port'),
            new SettingDefinition('mail.username', 'string', config: 'mail.mailers.smtp.username', default: '', group: 'email', label: 'SMTP username'),
            new SettingDefinition('mail.password', 'string', encrypted: true, config: 'mail.mailers.smtp.password', default: '', group: 'email', label: 'SMTP password'),
            new SettingDefinition('mail.scheme', 'string', config: 'mail.mailers.smtp.scheme', default: '', group: 'email', label: 'SMTP encryption', options: ['', 'smtps']),

            // ── Moderation defaults (PART 3.4) ──────────────────────────────────────────────────────
            // new_user_hold_posts honours the owner's HEARTH_NEW_USER_HOLD_POSTS env via the config path
            // (wired in config/hearth.php), so an unset DB row tracks env; a panel value overrides + persists.
            new SettingDefinition('moderation.new_user_hold_posts', 'int', config: 'hearth.antispam.new_user_moderation.posts', default: 2, group: 'moderation', label: 'New-user first-post hold count'),
            new SettingDefinition('moderation.suspicious_score', 'int', config: 'hearth.antispam.content.suspicious_score', default: 2, group: 'moderation', label: 'Suspicious-score hold threshold'),
            new SettingDefinition('moderation.max_links', 'int', config: 'hearth.antispam.content.max_links', default: 3, group: 'moderation', label: 'Links per post before suspicion'),
            new SettingDefinition('moderation.rate_tl0', 'int', config: 'hearth.antispam.rate_limits.tl0', default: 2, group: 'moderation', label: 'Flood limit — new users (posts/min)'),
            new SettingDefinition('moderation.rate_tl1', 'int', config: 'hearth.antispam.rate_limits.tl1', default: 8, group: 'moderation', label: 'Flood limit — TL1 (posts/min)'),
            new SettingDefinition('moderation.rate_default', 'int', config: 'hearth.antispam.rate_limits.default', default: 20, group: 'moderation', label: 'Flood limit — established (posts/min)'),

            // ── Anti-spam (PART 3.5) ────────────────────────────────────────────────────────────────
            new SettingDefinition('antispam.captcha_provider', 'string', config: 'hearth.antispam.registration.captcha.provider', default: 'qa', group: 'antispam', label: 'CAPTCHA provider', options: ['qa', 'turnstile', 'none']),
            new SettingDefinition('antispam.turnstile_site_key', 'string', config: 'hearth.antispam.registration.captcha.turnstile.site_key', default: '', group: 'antispam', label: 'Turnstile site key'),
            new SettingDefinition('antispam.turnstile_secret', 'string', encrypted: true, config: 'hearth.antispam.registration.captcha.turnstile.secret', default: '', group: 'antispam', label: 'Turnstile secret key'),
            new SettingDefinition('antispam.sfs_use_api', 'bool', config: 'hearth.antispam.registration.stopforumspam.use_api', default: true, group: 'antispam', label: 'StopForumSpam live API'),

            // ── Appearance, site-level (PART 3.6) ───────────────────────────────────────────────────
            new SettingDefinition('appearance.active_theme', 'string', config: 'hearth.theme.active', default: '', group: 'appearance', label: 'Active theme'),
            new SettingDefinition('appearance.accent_color', 'string', default: '', group: 'appearance', label: 'Accent colour'),
            new SettingDefinition('appearance.forum_width', 'string', default: 'standard', group: 'appearance', label: 'Forum width', options: ['boxed-narrow', 'standard', 'wide', 'full']),
            new SettingDefinition('appearance.default_color_mode', 'string', default: 'auto', group: 'appearance', label: 'Default colour mode (visitors)', options: ['auto', 'light', 'dark']),
            new SettingDefinition('appearance.default_density', 'string', default: 'comfortable', group: 'appearance', label: 'Default density (visitors)', options: ['comfortable', 'compact']),
            new SettingDefinition('appearance.poster_position', 'string', default: 'left', group: 'appearance', label: 'Poster-info position', options: ['top', 'left', 'right']),
            new SettingDefinition('appearance.board_list_style', 'string', default: 'info-rich', group: 'appearance', label: 'Board-list style', options: ['info-rich', 'minimal']),
            new SettingDefinition('appearance.wordmark', 'string', default: '', group: 'appearance', label: 'Wordmark text'),
        ];
    }
}
