<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/**
 * Progressive Web App surface (Phase 4 · M3.1): the web app manifest + the service worker. Both are served
 * from the application root so the SW controls the whole origin scope. Progressive enhancement: a browser
 * without SW support simply ignores them and the site works unchanged.
 */
class PwaController extends Controller
{
    /** The web app manifest — makes the site installable. */
    public function manifest(): JsonResponse
    {
        $name = (string) config('app.name', 'NovFora');

        return response()->json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => 'Community forum',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'theme_color' => '#2563eb',
            'background_color' => '#0f172a',
            'icons' => [
                ['src' => '/icons/novfora.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'],
                ['src' => '/favicon.ico', 'sizes' => '48x48', 'type' => 'image/x-icon'],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /** The service worker script, served at the root so it can claim the whole-origin scope. */
    public function serviceWorker(): Response
    {
        $path = resource_path('pwa/service-worker.js');
        $body = File::exists($path) ? (string) File::get($path) : '';

        return response($body, 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Service-Worker-Allowed' => '/',
            // Let the browser revalidate the SW on each load so a deploy ships promptly (HTTP best practice).
            'Cache-Control' => 'no-cache',
        ]);
    }
}
