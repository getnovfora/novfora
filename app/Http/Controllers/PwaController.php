<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/**
 * Progressive Web App surface (Phase 4 · M3.1; subpath-aware per ADR-0078): the web app manifest + the
 * service worker. Both derive their scope from the app's mount BASE PATH so the PWA installs and the SW
 * registers correctly both at a domain/subdomain root ("/") AND under a subdirectory mount ("/community/")
 * — the RH-4/ADR-0070 deferral. At a root the base path is empty, so every value is byte-identical to the
 * pre-ADR-0078 behaviour (a strict no-op). Progressive enhancement: a browser without SW support simply
 * ignores them and the site works unchanged.
 */
class PwaController extends Controller
{
    /** The web app manifest — makes the site installable. */
    public function manifest(): JsonResponse
    {
        $name = (string) config('app.name', 'NovFora');
        $root = $this->basePath().'/'; // "/" at a root mount, "/community/" under a subdirectory mount

        return response()->json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => 'Community forum',
            'start_url' => $root,
            'scope' => $root,
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'theme_color' => '#245fbb',      // Nova Blue — the brand primary (installed-app chrome)
            'background_color' => '#0b0b10',  // obsidian — the splash matches the dark app icon
            // Icon srcs go through asset() so they inherit ASSET_URL / the subdirectory prefix. The SVG is the
            // crisp any-size maskable icon; the rasters give Android/Chrome the 192 + 512 it wants for the
            // richest install prompt, plus a dedicated full-bleed maskable-512 (no transparent corners).
            'icons' => [
                ['src' => asset('icons/novfora.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'],
                ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('icons/maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => asset('favicon.ico'), 'sizes' => '48x48', 'type' => 'image/x-icon'],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /** The service worker script. The registration scope (set in the page head) must be <= this allowed path. */
    public function serviceWorker(): Response
    {
        $path = resource_path('pwa/service-worker.js');
        $body = File::exists($path) ? (string) File::get($path) : '';

        return response($body, 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            // The SW can only claim a scope <= the allowed path; equal is fine. "/" at a root, "/community/"
            // under a subdir mount — so the SW controls the whole mount, never the parent site.
            'Service-Worker-Allowed' => $this->basePath().'/',
            // Let the browser revalidate the SW on each load so a deploy ships promptly (HTTP best practice).
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * The app's mount base path: "" at a domain/subdomain root, "/community" under a subdirectory mount.
     * Derived from url('/') so it inherits whatever root the RH-4 base-path detector / APP_URL established —
     * no separate config and no hard-coded "/" (the pre-ADR-0078 bug).
     */
    private function basePath(): string
    {
        return rtrim(parse_url(url('/'), PHP_URL_PATH) ?: '/', '/');
    }
}
