<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubService;
use App\Embeds\EmbedManager;
use App\Models\AclEntry;
use App\Models\EmbedSite;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Topic;
use App\Permissions\PermissionValue;
use App\Permissions\VisibleForumIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Users;

/*
| U7 (ADR-0103) — the APEX coverage: the untrusted-embedding boundary. Forged/cross-origin/oversized/
| malformed requests, the guest no-leak fence (private forums + private clubs), key lifecycle authority,
| statelessness (no cookies), and the rate limit. Every denial is the same 404 — no existence oracle.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Cache::flush();
    config(['novfora.embeds.enabled' => true]);
});

function secSite(?array $widgets = null, string $origin = 'https://partner.example'): EmbedSite
{
    return app(EmbedManager::class)->create('Partner', $origin, $widgets);
}

function secPublicTopic(string $title = 'Public Topic'): Forum
{
    $forum = Forum::create(['slug' => 'pub-'.uniqid(), 'title' => 'Public', 'type' => 'forum']);
    Topic::create([
        'slug' => 'pt-'.uniqid(), 'title' => $title, 'forum_id' => $forum->id,
        'approved_state' => 'approved', 'last_posted_at' => now(),
    ]);

    return $forum;
}

/** A forum guests can never see (the FeedTest idiom), with one topic carrying a unique token. */
function secPrivateForum(string $token = 'ZZEMBEDSECRET'): Forum
{
    $forum = Forum::create(['slug' => 'priv-'.uniqid(), 'title' => 'Private', 'type' => 'forum']);
    Topic::create([
        'slug' => 'st-'.uniqid(), 'title' => $token, 'forum_id' => $forum->id,
        'approved_state' => 'approved', 'last_posted_at' => now(),
    ]);
    $guests = Group::where('slug', 'guests')->firstOrFail();
    AclEntry::create([
        'permission_key' => 'forum.view', 'holder_type' => 'group', 'holder_id' => $guests->id,
        'scope_type' => 'forum', 'scope_id' => $forum->id, 'value' => PermissionValue::Never->value,
    ]);
    VisibleForumIds::flush();
    Cache::flush();

    return $forum;
}

it('404s everything while the feature switch is off', function () {
    $site = secSite();
    secPublicTopic();
    config(['novfora.embeds.enabled' => false]);

    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key]))->assertNotFound();
    $this->get(route('embed.data', ['widget' => 'topics', 'site' => $site->key]))->assertNotFound();
});

it('404s a missing, unknown, oversized, or disabled site key identically', function () {
    secPublicTopic();
    $site = secSite();

    $this->get(route('embed.widget', ['widget' => 'topics']))->assertNotFound();
    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => 'emb_doesnotexist']))->assertNotFound();
    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => str_repeat('a', 500)]))->assertNotFound();

    app(EmbedManager::class)->update($site, ['is_enabled' => false]);
    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key]))->assertNotFound();
});

it('stops honouring a rotated key immediately and honours the replacement', function () {
    $site = secSite();
    secPublicTopic();
    $oldKey = $site->key;

    $newKey = app(EmbedManager::class)->rotate($site);

    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $oldKey]))->assertNotFound();
    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $newKey]))->assertOk();
});

it('404s unknown widgets and malformed parameters', function () {
    $site = secSite();
    secPublicTopic();

    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key, 'forum' => 'abc']))->assertNotFound();
    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key, 'forum' => '-1']))->assertNotFound();
    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key, 'forum' => str_repeat('9', 40)]))->assertNotFound();
    $this->get('/embed/v1/w/doesnotexist?site='.$site->key)->assertNotFound();
    $this->get('/embed/v1/w/'.str_repeat('a', 60).'?site='.$site->key)->assertNotFound();
});

it('never leaks a guest-invisible forum through the scoped widget (same 404 as nonexistent)', function () {
    $site = secSite();
    $private = secPrivateForum();

    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key, 'forum' => $private->id]))
        ->assertNotFound();
    $this->get(route('embed.data', ['widget' => 'topics', 'site' => $site->key, 'forum' => $private->id]))
        ->assertNotFound();
    // Indistinguishable from a forum that does not exist at all.
    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key, 'forum' => 999999]))
        ->assertNotFound();
});

it('never leaks guest-invisible topics through the board-wide widget', function () {
    $site = secSite();
    secPublicTopic('Visible Embedded Topic');
    secPrivateForum('ZZEMBEDSECRET');

    $res = $this->get(route('embed.data', ['widget' => 'topics', 'site' => $site->key]));

    $res->assertOk()->assertSee('Visible Embedded Topic')->assertDontSee('ZZEMBEDSECRET');
});

it('never leaks a private club forum through the embed surface', function () {
    $site = secSite();
    $owner = Users::inGroups(['members', 'tl2'], ['email' => 'embed-club-'.uniqid().'@embed.test']);
    $club = app(ClubService::class)->create($owner, ['name' => 'Embed Leak Club '.uniqid(), 'privacy' => 'private']);
    $clubForum = $club->forum;
    Topic::create([
        'slug' => 'ct-'.uniqid(), 'title' => 'QQCLUBSECRET', 'forum_id' => $clubForum->id,
        'approved_state' => 'approved', 'last_posted_at' => now(),
    ]);
    VisibleForumIds::flush();
    Cache::flush();

    $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key, 'forum' => $clubForum->id]))
        ->assertNotFound();
    $this->get(route('embed.data', ['widget' => 'topics', 'site' => $site->key]))
        ->assertOk()->assertDontSee('QQCLUBSECRET');
});

it('escapes hostile content in the server-rendered widget', function () {
    $site = secSite();
    $forum = Forum::create(['slug' => 'xss-'.uniqid(), 'title' => 'XSS', 'type' => 'forum']);
    Topic::create([
        'slug' => 'x-'.uniqid(), 'title' => '<script>alert(1)</script>', 'forum_id' => $forum->id,
        'approved_state' => 'approved', 'last_posted_at' => now(),
    ]);

    $res = $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key, 'forum' => $forum->id]));

    $res->assertOk()
        ->assertSee('<script>alert(1)</script>') // escaped form (assertSee escapes by default)
        ->assertDontSee('<script>alert(1)</script>', false); // raw form absent
});

it('grants CORS only to the registered origin', function () {
    $site = secSite(origin: 'https://partner.example');
    secPublicTopic();
    $url = route('embed.data', ['widget' => 'topics', 'site' => $site->key]);

    $match = $this->get($url, ['Origin' => 'https://partner.example']);
    $match->assertOk();
    expect($match->headers->get('Access-Control-Allow-Origin'))->toBe('https://partner.example');
    expect((string) $match->headers->get('Vary'))->toContain('Origin');

    foreach (['https://evil.example', 'http://partner.example', 'https://partner.example.evil.com', 'null'] as $origin) {
        $miss = $this->get($url, ['Origin' => $origin]);
        $miss->assertOk();
        expect($miss->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    }
});

it('scopes frame-ancestors to the registered origin only', function () {
    $siteA = secSite(origin: 'https://partner.example');
    $siteB = app(EmbedManager::class)->create('Other', 'https://other.example');
    secPublicTopic();

    $csp = (string) $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $siteA->key]))
        ->headers->get('Content-Security-Policy');

    expect($csp)->toContain("frame-ancestors 'self' https://partner.example")
        ->not->toContain('other.example');
});

it('is stateless: no session cookie is ever set on embed responses', function () {
    $site = secSite();
    secPublicTopic();

    $html = $this->get(route('embed.widget', ['widget' => 'topics', 'site' => $site->key]));
    $json = $this->get(route('embed.data', ['widget' => 'topics', 'site' => $site->key]));

    expect($html->headers->allPreserveCase()['Set-Cookie'] ?? [])->toBe([]);
    expect($json->headers->allPreserveCase()['Set-Cookie'] ?? [])->toBe([]);
});

it('rate limits the embed endpoints per IP', function () {
    $site = secSite();
    secPublicTopic();
    config(['novfora.embeds.rate_limit' => 3]);

    $url = route('embed.data', ['widget' => 'topics', 'site' => $site->key]);
    foreach (range(1, 3) as $n) {
        $this->get($url)->assertOk();
    }
    $this->get($url)->assertStatus(429);
});
