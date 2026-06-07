<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Vite;

/*
| RH-5 sanity net: the committed Vite build (public/build) must be internally consistent — a rendered page
| may only reference asset hashes that actually exist on disk. /public/build is committed BY DESIGN (the
| baseline shared host has no Node), so a stale manifest (one that names a hash no longer on disk, or an
| orphaned hash the manifest no longer names) would 404 the page's CSS/JS on a real host. The CI
| "assets-fresh" guard proves the COMMITTED assets equal a fresh `npm run build`; this complements it from
| inside the app — it pins the manifest -> disk contract that the runtime @vite() resolution depends on, with
| no Node required to run.
*/

it('renders the app entrypoints to asset URLs that all exist on disk', function () {
    // Force manifest (production) resolution regardless of a stray gitignored `public/hot` left by
    // `npm run dev` — otherwise Vite would emit dev-server URLs instead of /build/ hashes and this guard,
    // which is about the COMMITTED build artifacts, would resolve nothing.
    app(Vite::class)->useHotFile(storage_path('framework/testing/vite-no-hot-'.uniqid()));

    // Exactly what every page's <head> emits via @vite([...]) — resolved through the committed manifest.
    $html = (string) app(Vite::class)(['resources/css/app.css', 'resources/js/app.js']);

    // Every /build/... URL the rendered tags point at (href/src/modulepreload), de-duplicated.
    preg_match_all('#/build/[^"\'\s>]+#', $html, $matches);
    $referenced = array_values(array_unique($matches[0]));

    expect($referenced)->not->toBeEmpty('the rendered @vite tags referenced no build assets');

    foreach ($referenced as $url) {
        $relative = ltrim((string) parse_url($url, PHP_URL_PATH), '/'); // build/assets/app-XXXX.css
        expect(is_file(public_path($relative)))
            ->toBeTrue("rendered page references a missing asset: {$relative}");
    }
});

it('has a manifest whose every referenced file exists in public/build', function () {
    $manifestPath = public_path('build/manifest.json');
    expect(is_file($manifestPath))->toBeTrue('public/build/manifest.json is missing');

    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

    foreach ($manifest as $key => $chunk) {
        expect(is_file(public_path('build/'.$chunk['file'])))
            ->toBeTrue("manifest entry [{$key}] points at a missing file: {$chunk['file']}");

        // CSS sidecars and nested imports referenced by an entry must resolve too.
        foreach (($chunk['css'] ?? []) as $css) {
            expect(is_file(public_path('build/'.$css)))
                ->toBeTrue("manifest entry [{$key}] references a missing CSS file: {$css}");
        }
    }
});
