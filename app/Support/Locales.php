<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support;

/**
 * The single source of truth for which locales the UI may use and how each is written.
 *
 * Everything that touches an untrusted locale value — the SetLocale middleware, the language switcher,
 * the <html dir> attribute — funnels through here so the allowlist is enforced in exactly one place.
 * `config('novfora.locales')` is the data; this class is the guard. There is no path that calls
 * App::setLocale() with a code that has not passed isSupported() first.
 */
final class Locales
{
    /**
     * @return array<string, array{name: string, native: string, dir: string}>
     */
    public static function all(): array
    {
        /** @var array<string, array{name: string, native: string, dir: string}> $locales */
        $locales = config('novfora.locales', ['en' => ['name' => 'English', 'native' => 'English', 'dir' => 'ltr']]);

        return $locales;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function isSupported(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::all());
    }

    /** The configured default, falling back to en if it is somehow not in the allowlist. */
    public static function default(): string
    {
        $default = (string) config('app.locale', 'en');

        return self::isSupported($default) ? $default : 'en';
    }

    /** Writing direction for a locale — 'ltr' unless the allowlist marks it 'rtl'. Unknown codes are LTR. */
    public static function direction(?string $code): string
    {
        $entry = self::all()[$code] ?? null;

        return ($entry['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
    }

    public static function isRtl(?string $code): bool
    {
        return self::direction($code) === 'rtl';
    }

    public static function nativeName(?string $code): string
    {
        $entry = self::all()[$code] ?? null;

        return (string) ($entry['native'] ?? $entry['name'] ?? (string) $code);
    }
}
