<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue as V;
use App\Permissions\VisibleForumIds;
use App\Search\SearchQuery;
use App\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Search facets (P2-M4 ◐) on the DB Scout driver (the baseline). author / forum / date / tag / type each
| narrow results; EVERY query threads VisibleForumIds so a restricted viewer can never retrieve a post from a
| forum they cannot see — through the forum facet or any other path. Forced-absence: all facets combine
| correctly with no external engine. The Meili translation is asserted at the string level.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
    $this->seed();
});

function facetForum(string $slug): Forum
{
    return Forum::create(['slug' => $slug, 'title' => ucfirst($slug), 'type' => 'forum']);
}

/** A member who can see every forum (VisibleForumIds → null). */
function facetViewer(): User
{
    return Users::inGroups(['members', 'tl1']);
}

it('filters by author', function () {
    $forum = facetForum('a');
    $alice = Users::inGroups(['members', 'tl1'], ['username' => 'alice']);
    $bob = Users::inGroups(['members', 'tl1'], ['username' => 'bob']);
    app(PostService::class)->createTopic($alice, $forum, 'TA', 'tiptap_json', Content::doc('shared keyword zeta'));
    app(PostService::class)->createTopic($bob, $forum, 'TB', 'tiptap_json', Content::doc('shared keyword zeta'));

    $q = new SearchQuery(viewer: facetViewer(), term: 'zeta', authorId: (int) $alice->id);
    $results = app(SearchService::class)->search($q);

    expect($results)->toHaveCount(1)
        ->and((int) $results->first()->user_id)->toBe((int) $alice->id);
});

it('filters by forum', function () {
    $a = facetForum('aa');
    $b = facetForum('bb');
    app(PostService::class)->createTopic(facetViewer(), $a, 'TA', 'tiptap_json', Content::doc('omega here'));
    app(PostService::class)->createTopic(facetViewer(), $b, 'TB', 'tiptap_json', Content::doc('omega here'));

    $q = new SearchQuery(viewer: facetViewer(), term: 'omega', forumId: (int) $a->id);
    $results = app(SearchService::class)->search($q);

    expect($results)->toHaveCount(1)
        ->and((int) $results->first()->topic->forum_id)->toBe((int) $a->id);
});

it('filters by date range', function () {
    $forum = facetForum('a');
    $old = app(PostService::class)->createTopic(facetViewer(), $forum, 'Old', 'tiptap_json', Content::doc('chrono marker'));
    $new = app(PostService::class)->createTopic(facetViewer(), $forum, 'New', 'tiptap_json', Content::doc('chrono marker'));
    Post::where('topic_id', $old->id)->update(['created_at' => Carbon::now()->subDays(10)]);

    $q = new SearchQuery(viewer: facetViewer(), term: 'chrono', dateFrom: Carbon::now()->subDays(3)->startOfDay());
    $results = app(SearchService::class)->search($q);

    expect($results)->toHaveCount(1)
        ->and((int) $results->first()->topic_id)->toBe((int) $new->id);
});

it('filters by tag (topic-level, joined via the post→topic→taggables path)', function () {
    $forum = facetForum('a');
    $tagged = app(PostService::class)->createTopic(facetViewer(), $forum, 'Tagged', 'tiptap_json', Content::doc('taggable lore'));
    app(PostService::class)->createTopic(facetViewer(), $forum, 'Untagged', 'tiptap_json', Content::doc('taggable lore'));
    $tag = Tag::create(['name' => 'Lore', 'slug' => 'lore']);
    $tagged->tags()->attach($tag->id);

    $q = new SearchQuery(viewer: facetViewer(), term: 'taggable', tagIds: [(int) $tag->id]);
    $results = app(SearchService::class)->search($q);

    expect($results)->toHaveCount(1)
        ->and((int) $results->first()->topic_id)->toBe((int) $tagged->id);
});

it('filters by type=topic (opening posts only)', function () {
    $forum = facetForum('a');
    $topic = app(PostService::class)->createTopic(facetViewer(), $forum, 'Thread', 'tiptap_json', Content::doc('sigil one'));
    app(PostService::class)->reply(facetViewer(), $topic, 'tiptap_json', Content::doc('sigil two'));
    app(PostService::class)->reply(facetViewer(), $topic, 'tiptap_json', Content::doc('sigil three'));

    $viewer = facetViewer();
    expect(app(SearchService::class)->search(new SearchQuery(viewer: $viewer, term: 'sigil')))->toHaveCount(3)
        ->and(app(SearchService::class)->search(new SearchQuery(viewer: $viewer, term: 'sigil', type: 'topic')))->toHaveCount(1);
});

it('combines every facet on the DB driver (forced-absence)', function () {
    $forum = facetForum('target');
    $other = facetForum('other');
    $alice = Users::inGroups(['members', 'tl1'], ['username' => 'alice']);
    $bob = Users::inGroups(['members', 'tl1'], ['username' => 'bob']);

    // The ONE post that satisfies all facets: alice, in $forum, recent, tagged, an opening post, matching 'apex'.
    $hit = app(PostService::class)->createTopic($alice, $forum, 'Hit', 'tiptap_json', Content::doc('apex content'));
    $tag = Tag::create(['name' => 'Pick', 'slug' => 'pick']);
    $hit->tags()->attach($tag->id);
    // Decoys that each break exactly one facet.
    app(PostService::class)->createTopic($bob, $forum, 'WrongAuthor', 'tiptap_json', Content::doc('apex content'))->tags()->attach($tag->id);
    app(PostService::class)->createTopic($alice, $other, 'WrongForum', 'tiptap_json', Content::doc('apex content'))->tags()->attach($tag->id);
    app(PostService::class)->reply($alice, $hit, 'tiptap_json', Content::doc('apex reply')); // not an opening post

    $q = new SearchQuery(
        viewer: facetViewer(),
        term: 'apex',
        authorId: (int) $alice->id,
        forumId: (int) $forum->id,
        dateFrom: Carbon::now()->subDay()->startOfDay(),
        tagIds: [(int) $tag->id],
        type: 'topic',
    );
    $results = app(SearchService::class)->search($q);

    expect($results)->toHaveCount(1)
        ->and((int) $results->first()->topic_id)->toBe((int) $hit->id);
});

it('never leaks a post from a forum the viewer cannot see — via the forum facet or otherwise', function () {
    $acl = Acl::make();
    $hidden = Forum::findOrFail($acl->forum->id);
    app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $hidden, 'Secret', 'tiptap_json', Content::doc('classified vault'));
    $acl->grant('members', 'forum.view', $acl->forumScope, V::Never);
    VisibleForumIds::flush();

    $restricted = $acl->user(['members', 'tl1']);

    // No facet → the hidden forum's post is excluded by the visibility gate.
    expect(app(SearchService::class)->search(new SearchQuery(viewer: $restricted, term: 'classified')))->toBeEmpty();
    // Forum facet aimed AT the hidden forum → still empty (visibility ∩ facet = ∅).
    expect(app(SearchService::class)->search(new SearchQuery(viewer: $restricted, term: 'classified', forumId: (int) $hidden->id)))->toBeEmpty();
    // An admin who sees all DOES find it.
    expect(app(SearchService::class)->search(new SearchQuery(viewer: Users::inGroups(['admins']), term: 'classified')))->toHaveCount(1);
});

it('returns empty immediately when the viewer can see no forum at all', function () {
    $acl = Acl::make();
    app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $acl->forum, 'Anything', 'tiptap_json', Content::doc('void marker'));
    $acl->grant('members', 'forum.view', $acl->global, V::Never); // sees nothing anywhere
    VisibleForumIds::flush();

    $blind = $acl->user(['members', 'tl1']);

    expect(VisibleForumIds::for($blind))->toBe([])
        ->and(app(SearchService::class)->search(new SearchQuery(viewer: $blind, term: 'void')))->toBeEmpty();
});

it('translates facets into Meilisearch native filter clauses', function () {
    $q = new SearchQuery(
        viewer: facetViewer(),
        term: 'x',
        authorId: 7,
        dateFrom: Carbon::createFromTimestamp(1_700_000_000),
        dateTo: Carbon::createFromTimestamp(1_701_000_000),
    );

    $clauses = app(SearchService::class)->meiliFilter($q, [3, 4]);

    expect($clauses)->toBe([
        'forum_id IN [3, 4]',
        'user_id = 7',
        'created_at >= 1700000000',
        'created_at <= 1701000000',
    ]);
});
