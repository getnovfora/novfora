<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Settings;

use App\Models\Setting;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * The typed site-settings service (ACP v1, PART 0).
 *
 * PRECEDENCE (ADR-0023): for any registered key, the effective value is resolved in order —
 *   1. a row in the `settings` table  (an explicit panel override; persists across deploys), else
 *   2. the registry's config() path   (config files already fold env() → hardcoded default), else
 *   3. the registry's literal default.
 * Defaults are NOT seeded as rows — that would shadow the env/config fallback and defeat the whole point
 * of the override (e.g. the owner's HEARTH_NEW_USER_HOLD_POSTS keeps governing until an admin sets a
 * value in the panel, after which the panel value wins and survives the next release).
 *
 * CACHING (RH-9): the whole bag is read ONCE per request and cached as PRIMITIVES only (the raw string
 * column + type + encrypted flag — never an Eloquent model, never a decrypted secret). Decryption and
 * typing happen after the cache boundary, in-process. Writes are write-through: the row is upserted and
 * the cached bag invalidated immediately.
 *
 * SECURITY: secret settings (SMTP password, Turnstile secret) are stored Crypt::encryptString(...) under
 * the app key, never echoed back into forms (the forms use placeholder/"leave blank to keep" semantics),
 * and masked in the audit log (who / key / old→new).
 *
 * Bound as a singleton so the per-request memo survives across the many reads a request makes.
 */
class Settings
{
    public const CACHE_KEY = 'hearth:settings:bag';

    /**
     * Per-request memo of the primitive bag. null = not yet loaded this request.
     *
     * @var array<string,array{value:?string,type:string,encrypted:bool}>|null
     */
    private ?array $memo = null;

    /** Per-request memo of the display-only siteView() bag (the view composer fires it on every render). */
    private ?array $siteViewMemo = null;

    /**
     * The whole bag of overridden keys, as cache-safe primitives. Defensive: if the table doesn't exist
     * yet (pre-install / mid-migration) the empty bag is returned and — crucially — NOT cached, so a real
     * override isn't masked by a cached empty read after the table appears.
     *
     * @return array<string,array{value:?string,type:string,encrypted:bool}>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        // The whole read is defensive: the cache store itself can be the database (the baseline default),
        // so even Cache::get() can throw before the DB exists (pre-install, package:discover during a
        // release build). On any failure, track env/config and don't poison the cache.
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $this->memo = $cached;
            }

            $bag = Setting::query()
                ->get(['key', 'value', 'type', 'is_encrypted'])
                ->mapWithKeys(fn (Setting $s) => [$s->key => [
                    'value' => $s->value,
                    'type' => $s->type,
                    'encrypted' => (bool) $s->is_encrypted,
                ]])
                ->all();

            Cache::forever(self::CACHE_KEY, $bag);

            return $this->memo = $bag;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Does an explicit panel override row exist for this key? (Distinct from "has an effective value".) */
    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function definition(string $key): ?SettingDefinition
    {
        return SettingsRegistry::get($key);
    }

    /**
     * The effective, fully-typed value for a key (DB override → config → literal default). For a secret
     * with an override, this returns the DECRYPTED value — only call it where the plaintext is needed
     * (e.g. applying SMTP config), never to render a form field.
     */
    public function get(string $key): mixed
    {
        $def = SettingsRegistry::get($key);
        if ($def === null) {
            return null;
        }

        $bag = $this->all();
        if (isset($bag[$key])) {
            $raw = $bag[$key]['value'];
            if ($bag[$key]['encrypted'] && $raw !== null && $raw !== '') {
                try {
                    $raw = Crypt::decryptString($raw);
                } catch (\Throwable) {
                    $raw = null; // undecryptable (e.g. key rotated) → fall through to config/default
                }
            }
            if ($raw !== null) {
                return $def->coerce($raw);
            }
        }

        if ($def->config !== null) {
            $fromConfig = config($def->config, $def->default);

            return $def->coerce($fromConfig ?? $def->default);
        }

        return $def->coerce($def->default);
    }

    public function string(string $key): string
    {
        return (string) ($this->get($key) ?? '');
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /** @return array<int|string,mixed> */
    public function array(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? $value : [];
    }

    /** Is a secret key actually configured (an override row, or a non-empty config/env value behind it)? */
    public function secretIsSet(string $key): bool
    {
        $def = SettingsRegistry::get($key);
        if ($def === null) {
            return false;
        }

        if ($this->has($key)) {
            return ($this->all()[$key]['value'] ?? '') !== '';
        }

        return $def->config !== null && (string) config($def->config, '') !== '';
    }

    /**
     * Write a setting through to the DB (upsert), invalidate the cache, and audit the change (masking
     * secrets). An empty value for a secret is treated as "leave unchanged" so the placeholder form never
     * blanks a configured password by accident.
     *
     * @throws \InvalidArgumentException for an unknown key
     */
    public function set(string $key, mixed $value): void
    {
        $def = SettingsRegistry::get($key);
        if ($def === null) {
            throw new \InvalidArgumentException("Unknown setting key: {$key}");
        }

        // Placeholder semantics: a blank secret means "keep the current value" — do nothing.
        if ($def->encrypted && ($value === null || $value === '')) {
            return;
        }

        $old = $this->get($key);
        $coerced = $def->coerce($value);

        $stored = $def->encrypted
            ? Crypt::encryptString((string) $coerced)
            : $def->serialize($coerced);

        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'type' => $def->type, 'is_encrypted' => $def->encrypted],
        );

        $this->invalidate();

        Audit::log('settings.updated', null, [
            'key' => $key,
            'old' => $this->mask($def, $old),
            'new' => $this->mask($def, $coerced),
        ]);
    }

    /** Remove an override row so the key reverts to its env/config fallback. Audited. */
    public function forget(string $key): void
    {
        $def = SettingsRegistry::get($key);
        if ($def === null || ! $this->has($key)) {
            return;
        }

        $old = $this->get($key);
        Setting::query()->where('key', $key)->delete();
        $this->invalidate();

        Audit::log('settings.reset', null, [
            'key' => $key,
            'old' => $this->mask($def, $old),
            'new' => '(reverted to env/config default)',
        ]);
    }

    /** Drop the cached bag + per-request memo. Called on every write. */
    public function invalidate(): void
    {
        $this->memo = null;
        $this->siteViewMemo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Push every DB-overridden, config-backed setting into the live config() so existing consumers
     * (the mailer, the anti-spam pipeline, app.name, the theme) honour the override with NO change to
     * their own code. Only keys that actually have an override row are applied — unset keys keep the
     * host's env/config. Run from the SettingsServiceProvider on boot (skipped pre-install).
     */
    public function applyToConfig(): void
    {
        $bag = $this->all();
        if ($bag === []) {
            return;
        }

        foreach (SettingsRegistry::all() as $key => $def) {
            if ($def->config === null || ! isset($bag[$key])) {
                continue;
            }
            config([$def->config => $this->get($key)]);
        }
    }

    /**
     * The display-only appearance/general bag shared with every view (one cache read). Secrets are never
     * included. Empty strings mean "use the built-in default" and the views fall back accordingly.
     *
     * @return array<string,mixed>
     */
    public function siteView(): array
    {
        return $this->siteViewMemo ??= [
            'site_name' => $this->string('general.site_name'),
            'site_description' => $this->string('general.site_description'),
            'notice' => $this->string('general.site_notice'),
            'wordmark' => $this->string('appearance.wordmark'),
            'accent_color' => $this->string('appearance.accent_color'),
            'forum_width' => $this->string('appearance.forum_width'),
            'default_color_mode' => $this->string('appearance.default_color_mode'),
            'default_density' => $this->string('appearance.default_density'),
            'poster_position' => $this->string('appearance.poster_position'),
            'board_list_style' => $this->string('appearance.board_list_style'),
        ];
    }

    /** Audit-safe rendering of a value: secrets are masked, arrays JSON-encoded, bools spelled out. */
    private function mask(SettingDefinition $def, mixed $value): string
    {
        if ($def->encrypted) {
            return $value === null || $value === '' ? '(unset)' : '••••••';
        }

        return match ($def->type) {
            'bool' => $value ? 'true' : 'false',
            'array' => (string) json_encode(array_values((array) $value)),
            default => $value === null ? '(unset)' : (string) $value,
        };
    }
}
