<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\ForumNode;
use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Support\Users;

/*
| Theme polish round 1 — the info-dense board view, sub-boards block, index "latest activity", and the topic
| poster sidebar. Presentation over already-maintained aggregates (Post::syncAggregates keeps reply_count /
| last_post_user_id / last_posted_at on topics and last_topic_id / last_posted_at on forums).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('renders the info-dense board table with starter, counts, and the last poster', function () {
    $starter = Users::inGroups(['members', 'tl2'], ['username' => 'starter', 'email' => 'starter@board.test']);
    $replier = Users::inGroups(['members', 'tl2'], ['username' => 'replier', 'email' => 'replier@board.test']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $svc = app(PostService::class);
    $topic = $svc->createTopic($starter, $forum, 'A discussion topic', 'markdown', ['source' => 'Opening post.']);
    $svc->reply($replier, $topic, 'markdown', ['source' => 'A helpful reply.']);

    $this->get(route('forums.show', $forum))->assertOk()
        ->assertSee('Subject')->assertSee('Replies')->assertSee('Views')->assertSee('Last post')
        ->assertSee('A discussion topic')
        ->assertSee('by starter')   // subject cell: "by <starter>"
        ->assertSee('replier');     // last-post cell: the last poster's name (≠ starter)
});

it('shows a Sub-boards block listing permission-visible child forums above the table', function () {
    $parent = Forum::create(['slug' => 'parent', 'title' => 'Parent Board', 'type' => 'forum']);
    Forum::create(['slug' => 'child', 'title' => 'Child Board', 'type' => 'forum', 'parent_id' => $parent->id, 'description' => 'A nested board.']);

    $this->get(route('forums.show', $parent))->assertOk()
        ->assertSee('Sub-boards')
        ->assertSee('Child Board');
});

it('shows a forum index row latest-activity once the forum has posts', function () {
    $author = Users::inGroups(['members', 'tl2'], ['username' => 'idxauthor', 'email' => 'idx@board.test']);
    $category = Forum::create(['slug' => 'cat', 'title' => 'Category', 'type' => 'category']);
    $forum = Forum::create(['slug' => 'busy', 'title' => 'Busy Forum', 'type' => 'forum', 'parent_id' => $category->id]);
    app(PostService::class)->createTopic($author, $forum, 'First topic', 'markdown', ['source' => 'Body.']);

    $this->get(route('forums.index'))->assertOk()
        ->assertSee('Busy Forum')
        ->assertSee('Latest activity')
        ->assertSee('First topic')   // F6: the latest-activity column now names the last topic ...
        ->assertSee('idxauthor');    // ... and its author
});

it('F6: the latest-activity column links the last topic + its author (no per-row query)', function () {
    $author = Users::inGroups(['members', 'tl2'], ['username' => 'lastposterx', 'email' => 'lastx@board.test']);
    $forum = Forum::create(['slug' => 'fa', 'title' => 'F A', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'The latest topic title', 'markdown', ['source' => 'Body.']);
    $forum->refresh();

    // Build the exact node + $lastTopics map the controller hands the partial, then render the partial in
    // isolation (unambiguous: the board index also hosts an activity feed that names topics/authors).
    $node = ForumNode::fromArray(ForumNode::toArray($forum));
    $lastTopics = Topic::query()
        ->whereIn('id', [$forum->last_topic_id])
        ->with('lastPostUser.groups')
        ->get(['id', 'title', 'slug', 'last_post_id', 'last_post_user_id'])
        ->keyBy('id');

    $html = Blade::render(
        '@include("forum.partials.forum-row", ["forum" => $forum, "lastTopics" => $lastTopics])',
        ['forum' => $node, 'lastTopics' => $lastTopics],
    );

    expect($html)
        ->toContain('The latest topic title')          // the last topic title ...
        ->toContain('lastposterx')                     // ... its author (via <x-ui.user-name>) ...
        ->toContain('#post-'.$topic->last_post_id);    // ... linked to the latest post
});

it('renders the topic poster sidebar (name + post count)', function () {
    $author = Users::inGroups(['members', 'tl2'], ['username' => 'poster', 'email' => 'poster@board.test']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Sidebar topic', 'markdown', ['source' => 'Body here.']);

    $this->get(route('topics.show', $topic))->assertOk()
        ->assertSee('poster')   // poster name in the sidebar
        ->assertSee('posts');   // "<n> posts" stat
});

it('marks an admin author with a staff badge in the poster sidebar', function () {
    $admin = Users::inGroups(['admins'], ['username' => 'bossadmin', 'email' => 'boss@board.test']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($admin, $forum, 'Staff topic', 'markdown', ['source' => 'Official.']);

    // View as the admin so the opening post is guaranteed visible (a held first post would be hidden from
    // a guest), then assert the derived staff badge renders in the poster sidebar.
    $this->actingAs($admin)->get(route('topics.show', $topic))->assertOk()->assertSee('Admin');
});
