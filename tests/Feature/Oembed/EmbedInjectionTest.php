<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Content\ContentSanitizer;
use App\Content\Oembed\SsrfGuard;
use App\Forum\PostService;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Users;

/*
| End-to-end embed rendering + the amendment #2 invariant: an embed NODE becomes a sandboxed iframe in the
| post's body_html_cache (injected AFTER sanitization), while the post ContentSanitizer itself STILL strips
| raw iframes — so the embed policy is the ONLY path that can produce one.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->app->bind(SsrfGuard::class, fn () => new SsrfGuard(fn () => ['8.8.8.8']));
    $this->forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $this->author = Users::inGroups(['members', 'tl2'], ['username' => 'author', 'email' => 'author@embed.test']);
});

function embedDoc(string $url): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Watch this:']]],
        ['type' => 'embed', 'attrs' => ['url' => $url]],
    ]];
}

it('injects a sandboxed iframe into body_html_cache for an embed node', function () {
    Http::fake(['*oembed*' => Http::response('{"title":"V"}', 200)]);

    $topic = app(PostService::class)->createTopic($this->author, $this->forum, 'An embed', 'tiptap_json', embedDoc('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
    $html = (string) $topic->posts()->first()->body_html_cache;

    expect($html)->toContain('<iframe')
        ->toContain('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->toContain('sandbox="')
        ->and(substr_count($html, '<iframe'))->toBe(1);
});

it('keeps the post ContentSanitizer stripping raw iframes (the embed policy is the only iframe path)', function () {
    $sanitized = app(ContentSanitizer::class)->sanitize('<p>hello</p><iframe src="https://evil.example/x"></iframe>');

    expect($sanitized)->toContain('<p>hello</p>')->not->toContain('<iframe')->not->toContain('evil.example');
});

it('renders a facade (no iframe) for an embed node when oEmbed is disabled — forced absence', function () {
    config(['novfora.oembed.enabled' => false]);

    $topic = app(PostService::class)->createTopic($this->author, $this->forum, 'Embed off', 'tiptap_json', embedDoc('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
    $html = (string) $topic->posts()->first()->body_html_cache;

    expect($html)->not->toContain('<iframe')->toContain('novfora-embed-facade');
});

it('renders a facade for a non-allowlisted embed-node URL', function () {
    $topic = app(PostService::class)->createTopic($this->author, $this->forum, 'Random embed', 'tiptap_json', embedDoc('https://random.example/post'));
    $html = (string) $topic->posts()->first()->body_html_cache;

    expect($html)->toContain('novfora-embed-facade')->not->toContain('<iframe');
});
