<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Permissions\PermissionValue as V;
use App\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Acl;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Search (ADR-0010): Scout DB driver over the post body_text projection, approved-only, per-forum visible,
| and tier-graceful — a configured-but-absent Meilisearch degrades to the database, never erroring.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function searchForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

it('finds an approved post by its body text', function () {
    app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), searchForum(), 'Widgets', 'tiptap_json', Content::doc('a post about zorblaxonate gadgets'));

    $results = app(SearchService::class)->posts('zorblaxonate');

    expect($results->pluck('body_text')->implode(' '))->toContain('zorblaxonate');
});

it('excludes pending posts from search', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['moderators']), searchForum(), 'T', 'tiptap_json', Content::doc('op'));
    app(PostService::class)->reply(Users::inGroups(['members', 'tl0']), $topic, 'tiptap_json', Content::doc('held qwizzlement text')); // TL0 → pending

    expect(app(SearchService::class)->posts('qwizzlement'))->toBeEmpty();
});

it('degrades to the database when the search engine is absent (tier-graceful)', function () {
    // Index with the working baseline driver first…
    app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), searchForum(), 'T', 'tiptap_json', Content::doc('please frobnicate the gimbals'));

    // …then point Scout at an absent Meilisearch. The service must degrade, not throw.
    config(['scout.driver' => 'meilisearch', 'scout.meilisearch.host' => 'http://127.0.0.1:1', 'scout.meilisearch.key' => 'x']);

    $results = app(SearchService::class)->posts('frobnicate');

    expect($results->pluck('body_text')->implode(' '))->toContain('frobnicate');
});

it('does not leak posts from a forum the viewer cannot see', function () {
    $acl = Acl::make();
    $forum = Forum::findOrFail($acl->forum->id);
    app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'Hidden', 'tiptap_json', Content::doc('top secret snazzleberry plans'));
    $acl->grant('members', 'forum.view', $acl->forumScope, V::Never);

    // Assert on the result snippet ("top secret"), not the query term — the search box echoes the query.
    $this->actingAs($acl->user(['members']))->get(route('search.index', ['q' => 'snazzleberry']))->assertOk()->assertDontSee('top secret');
    $this->actingAs(Users::inGroups(['admins']))->get(route('search.index', ['q' => 'snazzleberry']))->assertOk()->assertSee('top secret');
});

it('returns typeahead suggestions as JSON', function () {
    app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), searchForum(), 'Widgets', 'tiptap_json', Content::doc('quux flibbertigibbet content'));

    $this->getJson(route('search.suggest', ['q' => 'flibbertigibbet']))
        ->assertOk()->assertJsonFragment(['title' => 'Widgets']);
});
