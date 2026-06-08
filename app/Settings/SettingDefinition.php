<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Settings;

/**
 * One entry in the {@see SettingsRegistry} — the authoritative description of a single site setting:
 * its data type, whether it is a secret, where its fallback value comes from (a config path that itself
 * resolves env() → hardcoded default), and the literal default of last resort. This is the single source
 * of truth for the documented precedence (DB row → env via config → literal default), for type coercion,
 * and for the labels the ACP quick-search indexes. Pure data — no behaviour, Larastan-clean.
 */
final readonly class SettingDefinition
{
    public const TYPES = ['string', 'int', 'float', 'bool', 'array'];

    /**
     * @param  'string'|'int'|'float'|'bool'|'array'  $type
     * @param  string|null  $config  config() path whose value already resolves env()→default (or null for a pure-DB setting)
     * @param  list<string>|null  $options  allowed values for an enum-style string setting (UI + validation hint)
     */
    public function __construct(
        public string $key,
        public string $type = 'string',
        public bool $encrypted = false,
        public ?string $config = null,
        public mixed $default = null,
        public string $group = 'general',
        public string $label = '',
        public ?array $options = null,
    ) {}

    /** Coerce a raw (string|null from the store, or a mixed form input) value into this setting's PHP type. */
    public function coerce(mixed $value): mixed
    {
        if ($value === null) {
            return $this->type === 'bool' ? false : ($this->type === 'array' ? [] : null);
        }

        return match ($this->type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'int' => (int) $value,
            'float' => (float) $value,
            'array' => is_array($value) ? array_values($value) : (array) (json_decode((string) $value, true) ?? []),
            default => (string) $value,
        };
    }

    /** Serialise a typed value to the single string column (arrays → JSON; scalars → string). */
    public function serialize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this->type) {
            'bool' => $value ? '1' : '0',
            'array' => json_encode(array_values((array) $value)) ?: '[]',
            default => (string) $value,
        };
    }
}
