<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * RH-4.2 (ADR-0070) — request-time base-path detector for subdirectory installs.
 *
 * APEX surface: this runs on UNTRUSTED requests BEFORE any `.env`/`APP_URL` exists (the installer wizard at
 * `/community/install`). When the app is mounted in a subdirectory of a web root, every generated URL —
 * route(), Livewire's hashed update endpoint + livewire.js, and @vite/asset() — must carry the `/community`
 * prefix, or the wizard renders unstyled with a dead "Continue" (the symptom RH-4 fixes).
 *
 * How it works (and why it is safe):
 *   - The mount prefix is Symfony's `Request::getBasePath()` — which is computed from SCRIPT_NAME /
 *     SCRIPT_FILENAME / REQUEST_URI (i.e. honouring the web server's RewriteBase / alias), exactly the signal
 *     the spike calls for. On the supported layouts (Option A symlinked public/, Option B thin stub +
 *     RewriteBase, Option C copy) the front controller resolves under `/community`, so this is `/community`.
 *   - We then `URL::forceRootUrl(scheme://host + prefix)`. Because that prefix EQUALS the request's own base
 *     path, the forced root agrees with the request root, so Livewire's `getUpdateUri()` (which strips the
 *     base via the request, then FrontendAssets re-wraps it in `url()`) yields a SINGLE prefix, never a
 *     doubled `/community/community/...`. forcedRoot also feeds asset()/@vite (UrlGenerator::asset() falls
 *     back to formatRoot() = forcedRoot when no ASSET_URL is set), so one call covers all three surfaces.
 *   - It pins that root for the whole request lifecycle (so later sub-flows / a swapped request keep the
 *     prefix), and reads only the request — never config — so it holds pre-`.env` and under a cached config.
 *
 * Conservative by construction (the locked RH-4 fences):
 *   - Forces the root ONLY when APP_URL is unset/localhost — a real configured APP_URL is NEVER overridden.
 *   - A root / subdomain layout has an empty base path, so it is a STRICT NO-OP there (G4).
 *   - When the web server does not expose the subpath to PHP (empty base path on a subdir host), it does
 *     NOT force — that host's routing would already mis-resolve and needs a RewriteBase (runbook), not a
 *     forced URL root that would only double-prefix Livewire.
 */
class BasePathDetector
{
    /**
     * Force the URL/asset root to the detected subdirectory mount when it is safe to do so.
     * Returns the prefix that was forced (e.g. "/community"), or "" when nothing was forced.
     */
    public function apply(Request $request, ?string $configuredAppUrl): string
    {
        // Fence 1 — never override a real configured APP_URL. Only act pre-`.env` / on a localhost default.
        if (! $this->isUnsetOrLocalhost($configuredAppUrl)) {
            return '';
        }

        // Fence 2 (G4) — a root/subdomain layout has no mount prefix, so this is a strict no-op there.
        $prefix = $this->detectPrefix($request);
        if ($prefix === '') {
            return '';
        }

        // forcedRoot == the request's own root (host + base path), so it is consistent with Livewire's
        // base-stripping (no double prefix) and inherited by asset()/@vite via UrlGenerator::formatRoot().
        URL::forceRootUrl(rtrim($request->getSchemeAndHttpHost(), '/').$prefix);

        return $prefix;
    }

    /**
     * The subdirectory mount prefix (e.g. "/community"), derived from the web server's view of the front
     * controller via Symfony's base-path computation (SCRIPT_NAME/SCRIPT_FILENAME/REQUEST_URI, honouring
     * RewriteBase). "" means the root/subdomain layout (or a host that does not expose the subpath to PHP).
     */
    public function detectPrefix(Request $request): string
    {
        $base = rtrim(str_replace('\\', '/', $request->getBasePath()), '/');

        if ($base === '' || $base === '.') {
            return '';
        }

        return '/'.ltrim($base, '/');
    }

    /**
     * APP_URL is treated as "unset/localhost" when it is empty, has no host, or points at localhost / a
     * loopback address. Only then is it safe to derive the root from the (untrusted) request.
     */
    private function isUnsetOrLocalhost(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            // No parseable host (e.g. a bare path) — treat as unset for safety.
            return true;
        }

        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }
}
