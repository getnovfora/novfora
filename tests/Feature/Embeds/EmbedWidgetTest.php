<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Embeds\EmbedManager;
use App\Models\EmbedSite;
use App\Models\Forum;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
| U7 (ADR-0103) — the public embed surface, happy paths: server-rendered iframe/SSI widgets + the JSON
| the <novfora-*> web components consume. Adversarial coverage lives in EmbedSecurityTest.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Cache::flush();
    config(['novfora.embeds.enabled' => true]);
});

function embedSite(?array $widgets = null, string $origin = 'https://partner.example'): EmbedSite
{
    return app(EmbedManager::class)->create('Partner', $origin, $widgets);
}

function embedForumWithTopics(int $count = 2): Forum
{
    $forum = Forum::create(['slug' => 'general-'.uniqid(), 'title' => 'General', 'type' => 'forum']);
    foreach (range(1, $count) as $n) {
        Topic::create([
            'slug' => 't'.$n.uniqid(), 'title' => "Embedded Topic {$n}", 'forum_id' => $forum->id,
            'approved_state' => 'approved', 'last_posted_at' => now()->subMinutes($n),
        ]);
    }

    return $forum;
}

it('renders the topics widget as a self-contained HTML document', function () {
    $site = embedSite();
    $forum = embedForumWithTopics();

    $res = $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key, 'forum' => $forum->id]));

    $res->assertOk()
        ->assertSee('Embedded Topic 1')
        ->assertSee('Embedded Topic 2')
        ->assertSee('<!DOCTYPE html>', false);

    // The response owns its security headers: frame-ancestors grants exactly the registered origin.
    expect((string) $res->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'self' https://partner.example")
        ->toContain("default-src 'none'");
    expect((string) $res->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60');
    expect((string) $res->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('renders the board-wide topics widget without a forum param', function () {
    $site = embedSite();
    embedForumWithTopics();

    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key]))
        ->assertOk()
        ->assertSee('Embedded Topic 1');
});

it('renders the stats widget with public aggregates', function () {
    $site = embedSite();
    embedForumWithTopics(3);

    $this->get(route('embed.widget', ['widget' => 'stats', 'site' => $site->key]))
        ->assertOk()
        ->assertSee('Members')
        ->assertSee('Topics')
        ->assertSee('Posts');
});

it('serves the topics JSON with the versioned contract shape', function () {
    $site = embedSite();
    $forum = embedForumWithTopics();

    $res = $this->get(route('embed.data', ['widget' => 'topics', 'site' => $site->key, 'forum' => $forum->id]));

    $res->assertOk()
        ->assertJsonPath('widget', 'topics')
        ->assertJsonPath('version', 1)
        ->assertJsonPath('data.title', 'General')
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.title', 'Embedded Topic 1');
    expect((string) $res->headers->get('Vary'))->toContain('Origin');
});

it('clamps the limit parameter instead of erroring', function () {
    $site = embedSite();
    $forum = embedForumWithTopics(2);

    $this->get(route('embed.data', ['widget' => 'topics', 'site' => $site->key, 'forum' => $forum->id, 'limit' => 999]))
        ->assertOk()
        ->assertJsonCount(2, 'data.items');

    $this->get(route('embed.data', ['widget' => 'topics', 'site' => $site->key, 'forum' => $forum->id, 'limit' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data.items');
});

it('applies the light and dark theme switch to the widget document', function () {
    $site = embedSite();
    embedForumWithTopics(1);

    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key, 'theme' => 'dark']))
        ->assertOk()->assertSee('data-theme="dark"', false);

    // An unknown theme value falls back to auto rather than erroring.
    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key, 'theme' => 'hotpink']))
        ->assertOk()->assertSee('data-theme="auto"', false);
});

it('respects a per-site widget allowlist', function () {
    $site = embedSite(widgets: ['stats']);

    $this->get(route('embed.widget', ['widget' => 'stats', 'site' => $site->key]))->assertOk();
    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key]))->assertNotFound();
});
