<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Acl;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| SEO (system-architecture §6): canonical URLs, Open Graph, schema.org DiscussionForumPosting JSON-LD, and an
| XML sitemap that excludes empty containers and content a guest cannot see; robots points at the sitemap.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('emits canonical, Open Graph, and DiscussionForumPosting JSON-LD on a topic', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'SEO topic', 'tiptap_json', Content::doc('hello seo world'));

    $response = $this->get(route('topics.show', $topic))->assertOk();
    $response->assertSee('rel="canonical"', false);
    $response->assertSee('property="og:title"', false);
    $response->assertSee('DiscussionForumPosting', false);
});

it('serves an XML sitemap with approved topics and excludes empty forums', function () {
    $forum = Forum::create(['slug' => 'active', 'title' => 'Active', 'type' => 'forum']);
    $empty = Forum::create(['slug' => 'empty', 'title' => 'Empty', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'Indexed topic', 'tiptap_json', Content::doc('op'));

    $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml');
    $response->assertSee(route('topics.show', $topic), false);
    $response->assertSee(route('forums.show', $forum), false);
    $response->assertDontSee(route('forums.show', $empty), false); // empty container excluded
});

it('excludes topics from forums a guest cannot see', function () {
    $acl = Acl::make();
    $forum = Forum::findOrFail($acl->forum->id);
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'Private topic', 'tiptap_json', Content::doc('op'));
    $acl->grant('guests', 'forum.view', $acl->forumScope, V::Never);

    $this->get('/sitemap.xml')->assertOk()->assertDontSee(route('topics.show', $topic), false);
});

it('serves robots.txt pointing at the sitemap', function () {
    $this->get('/robots.txt')->assertOk()->assertSee('Sitemap:')->assertSee(route('sitemap'));
});

it('ships no static public/robots.txt that would shadow the dynamic route', function () {
    // public/.htaccess rewrites to the front controller only when the request is NOT an existing file
    // (RewriteCond %{REQUEST_FILENAME} !-f), so a checked-in public/robots.txt would silently shadow the
    // dynamic route above — and its runtime Sitemap: URL (subdirectory-install aware) — on Apache/Baseline
    // hosts. Deleted in U20 (ADR-0108); this pins it so it can't quietly come back.
    expect(file_exists(public_path('robots.txt')))->toBeFalse();
});
