<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubService;
use App\Forum\PostService;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\PermissionResolver;
use App\Permissions\VisibleForumIds;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Users;

/*
| U20 (ADR-0108) leak fence: every NEW SEO surface (profile OG, og:site_name, the $metaDescription layout
| seam) must reuse the guest visibility fence — nothing may emit content from a hidden/private forum or club,
| and profile descriptions stay aggregate-only.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/**
 * A private club with one owner-authored topic carrying unique title/body tokens (mirrors the
 * ClubPrivacyLeakTest fixture idiom).
 *
 * @return array{0: User, 1: Topic}
 */
function seoLeakClubTopic(): array
{
    $owner = Users::inGroups(['members', 'tl2'], ['email' => 'seo-leak-'.uniqid().'@leak.test']);
    $club = app(ClubService::class)->create($owner, ['name' => 'Seo Leak Club '.uniqid(), 'privacy' => 'private']);
    $topic = app(PostService::class)->createTopic($owner, $club->forum, 'XXSEOSECRET topic', 'markdown', ['source' => 'YYSEOSECRET hidden body']);

    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
    Cache::flush();

    return [$owner, $topic];
}

it('404s a private-club topic page for a guest before any OG could render', function () {
    [, $topic] = seoLeakClubTopic();

    $this->get(route('topics.show', $topic))->assertNotFound();
});

it('keeps a guest profile view aggregate-only when the member’s posts are all club-private', function () {
    [$owner] = seoLeakClubTopic();

    // The meta/og description is synthesized from join date + public post_count — never post content.
    $this->get(route('profiles.show', $owner))->assertOk()
        ->assertSee('Member since', false)
        ->assertDontSee('XXSEOSECRET')
        ->assertDontSee('YYSEOSECRET');

    // The Posts tab itself stays fenced too (VisibleForumIds::for the VIEWER — here, a guest).
    $this->get(route('profiles.show', [$owner, 'tab' => 'posts']))->assertOk()
        ->assertDontSee('XXSEOSECRET')
        ->assertDontSee('YYSEOSECRET');
});

it('emits og:site_name on the forum index', function () {
    $this->get(route('forums.index'))->assertOk()->assertSee('property="og:site_name"', false);
});

it('emits exactly one meta description tag on pages using the layout seam', function () {
    app(Settings::class)->set('general.site_description', 'A cosy corner of the internet.');

    foreach ([route('forums.index'), route('trending.index'), route('clubs.index')] as $url) {
        $html = (string) $this->get($url)->assertOk()->getContent();
        expect(substr_count($html, 'name="description"'))->toBe(1);
        expect(substr_count($html, 'property="og:description"'))->toBe(1);
    }
});
