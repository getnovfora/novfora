<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Content\Oembed\OEmbedService;
use App\Content\Oembed\SsrfGuard;
use App\Models\OembedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A deterministic guard so the provider-metadata fetch resolves to a public IP in tests.
    $this->app->bind(SsrfGuard::class, fn () => new SsrfGuard(fn () => ['8.8.8.8']));
});

it('renders an allowlisted YouTube URL as a sandboxed iframe and caches it', function () {
    Http::fake(['*oembed*' => Http::response('{"title":"My Video"}', 200)]);

    $html = app(OEmbedService::class)->render('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    expect($html)->toContain('<iframe')
        ->toContain('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->toContain('sandbox="')
        ->toContain('My Video');
    expect(OembedCache::where('provider', 'youtube')->exists())->toBeTrue();

    // Second render of the SAME url → served from cache, no further fetch.
    Http::fake();
    app(OEmbedService::class)->render('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    Http::assertNothingSent();
});

it('still renders the iframe when the metadata fetch fails (best-effort title)', function () {
    Http::fake(fn () => throw new ConnectionException('down'));

    $html = app(OEmbedService::class)->render('https://vimeo.com/123456789');
    expect($html)->toContain('<iframe')->toContain('https://player.vimeo.com/video/123456789');
});

it('renders a non-allowlisted URL as a link-card facade, never an iframe', function () {
    $html = app(OEmbedService::class)->render('https://random.example/article');
    expect($html)->toContain('novfora-embed-facade')->not->toContain('<iframe');
});

it('renders a facade (never an iframe) when oEmbed is disabled — forced absence', function () {
    config(['novfora.oembed.enabled' => false]);

    $html = app(OEmbedService::class)->render('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    expect($html)->not->toContain('<iframe');
});
