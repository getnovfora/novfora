<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Embeds;

use App\Models\EmbedSite;
use App\Support\Audit;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Lifecycle authority for embed sites (U7, ADR-0103) — the only writer of `embed_sites`. Origins are
 * normalized/validated here (exact scheme://host[:port], no path/query/credentials, http(s) only) so the
 * CSP frame-ancestors / CORS grants built from them can never be widened by a malformed row. Every
 * lifecycle transition is audit-logged; site keys are never written to the audit log in full.
 */
final class EmbedManager
{
    /** @var list<string> the built-in widget slugs (the semver'd embed contract, ADR-0103) */
    public const WIDGETS = ['topics', 'stats'];

    /** Longest key we will even look up — anything longer is discarded before touching the DB. */
    private const MAX_KEY_LENGTH = 64;

    public function create(string $name, string $origin, ?array $widgets = null): EmbedSite
    {
        $site = EmbedSite::create([
            'name' => $this->normalizeName($name),
            'origin' => self::normalizeOrigin($origin),
            'key' => $this->generateKey(),
            'is_enabled' => true,
            'widgets' => $this->normalizeWidgets($widgets),
        ]);

        Audit::log('embed_site.created', $site, ['name' => $site->name, 'origin' => $site->origin]);

        return $site;
    }

    /**
     * @param  array{name?:string,origin?:string,widgets?:array<int,string>|null,is_enabled?:bool}  $input
     */
    public function update(EmbedSite $site, array $input): void
    {
        $changes = [];
        if (array_key_exists('name', $input)) {
            $changes['name'] = $this->normalizeName((string) $input['name']);
        }
        if (array_key_exists('origin', $input)) {
            $changes['origin'] = self::normalizeOrigin((string) $input['origin']);
        }
        if (array_key_exists('widgets', $input)) {
            $changes['widgets'] = $this->normalizeWidgets($input['widgets']);
        }
        if (array_key_exists('is_enabled', $input)) {
            $changes['is_enabled'] = (bool) $input['is_enabled'];
        }

        if ($changes === []) {
            return;
        }

        $site->update($changes);
        Audit::log('embed_site.updated', $site, ['name' => $site->name, 'origin' => $site->origin, 'enabled' => $site->is_enabled]);
    }

    /** Mint a replacement key; the old one stops resolving immediately. Returns the new key. */
    public function rotate(EmbedSite $site): string
    {
        $key = $this->generateKey();
        $site->update(['key' => $key]);

        Audit::log('embed_site.key_rotated', $site, ['name' => $site->name, 'key_suffix' => substr($key, -6)]);

        return $key;
    }

    public function delete(EmbedSite $site): void
    {
        Audit::log('embed_site.removed', $site, ['name' => $site->name, 'origin' => $site->origin]);
        $site->delete();
    }

    /**
     * Resolve a request's site key to an ENABLED site. Null-safe and bounded: absent, oversized, or
     * malformed keys never reach the database. Returns null on any miss — callers 404 (no oracle).
     */
    public function resolve(?string $key): ?EmbedSite
    {
        if (! is_string($key) || $key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
            return null;
        }

        return EmbedSite::query()->where('key', $key)->where('is_enabled', true)->first();
    }

    /**
     * Normalize an origin to exactly `scheme://host[:port]` (lowercased), or throw. Anything that could
     * widen the grant — paths, queries, fragments, credentials, wildcards, non-http(s) schemes — is refused.
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeOrigin(string $origin): string
    {
        $origin = trim($origin);
        if ($origin === '' || strlen($origin) > 255 || preg_match('/[\s\'"*]/', $origin) === 1) {
            throw new InvalidArgumentException(__('Enter the embedding site as scheme://host, e.g. https://example.com.'));
        }

        $parts = parse_url($origin);
        if ($parts === false) {
            throw new InvalidArgumentException(__('Enter the embedding site as scheme://host, e.g. https://example.com.'));
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || ($path !== '' && $path !== '/')
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(__('Enter the embedding site as scheme://host, e.g. https://example.com — no path, query, or credentials.'));
        }

        $port = isset($parts['port']) ? ':'.((int) $parts['port']) : '';

        return $scheme.'://'.$host.$port;
    }

    private function normalizeName(string $name): string
    {
        $name = Str::limit(trim($name), 100, '');
        if ($name === '') {
            throw new InvalidArgumentException(__('Give the embed site a name.'));
        }

        return $name;
    }

    /**
     * @param  array<int,mixed>|null  $widgets
     * @return list<string>|null
     */
    private function normalizeWidgets(?array $widgets): ?array
    {
        if ($widgets === null) {
            return null;
        }

        $clean = array_values(array_intersect(self::WIDGETS, array_map(strval(...), $widgets)));

        // An empty selection means "everything" — a site row that allows NO widget would be a trap.
        return $clean === [] || count($clean) === count(self::WIDGETS) ? null : $clean;
    }

    private function generateKey(): string
    {
        return 'emb_'.Str::random(40);
    }
}
