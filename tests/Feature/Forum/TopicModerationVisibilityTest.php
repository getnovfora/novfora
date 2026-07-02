<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Group;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| BETA-4 / NOV-88 — the permission-aware UI contract for topic moderation: every control renders IFF the
| capability the server enforces would admit the action (show ↔ works, hidden ↔ 403 — always asserted as a
| PAIR). Pitfall from the investigation: never assertSee('Lock') — the "Locked" status badge contains the
| substring; assert on the form action URL / dusk hooks instead.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    app(PermissionResolver::class)->flushMemo();
});

function tmvForum(string $slug): Forum
{
    return Forum::create(['slug' => $slug, 'title' => ucfirst($slug), 'type' => 'forum']);
}

it('hides the lock control from a plain member AND 403s the forged POST (the contract pair)', function () {
    $forum = tmvForum('tmv-a');
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'A thread', 'tiptap_json', Content::doc('op'));
    $member = Users::inGroups(['members', 'tl1']);

    $this->actingAs($member)->get(route('topics.show', $topic))
        ->assertOk()
        ->assertDontSee('action="'.route('topics.lock', $topic).'"', false);

    $this->actingAs($member)->post(route('topics.lock', $topic))->assertForbidden();
    expect($topic->fresh()->status)->toBe('open');
});

it('shows the lock control to a global moderator and the action works', function () {
    $forum = tmvForum('tmv-b');
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'A thread', 'tiptap_json', Content::doc('op'));
    $mod = Users::inGroups(['moderators']);

    $this->actingAs($mod)->get(route('topics.show', $topic))
        ->assertOk()
        ->assertSee('action="'.route('topics.lock', $topic).'"', false);

    $this->actingAs($mod)->post(route('topics.lock', $topic))->assertRedirect();
    expect($topic->fresh()->status)->toBe('locked');
});

it('scopes the lock control to the forums a delegated moderator actually moderates', function () {
    $forumA = tmvForum('tmv-scoped-a');
    $forumB = tmvForum('tmv-scoped-b');
    $posts = app(PostService::class);
    $author = Users::inGroups(['members', 'tl1']);
    $topicA = $posts->createTopic($author, $forumA, 'In A', 'tiptap_json', Content::doc('op'));
    $topicB = $posts->createTopic($author, $forumB, 'In B', 'tiptap_json', Content::doc('op'));

    // A per-forum delegation: topic.moderate ALLOW at forum A only (no staff group membership at all).
    $delegate = Users::inGroups(['members', 'tl1']);
    AclEntry::create([
        'permission_key' => 'topic.moderate',
        'holder_type' => 'user', 'holder_id' => $delegate->getKey(),
        'scope_type' => Scope::forum((int) $forumA->id)->type, 'scope_id' => $forumA->id,
        'value' => PermissionValue::Allow->value,
    ]);
    app(PermissionResolver::class)->flushMemo();

    $this->actingAs($delegate)->get(route('topics.show', $topicA))
        ->assertOk()->assertSee('action="'.route('topics.lock', $topicA).'"', false);
    $this->actingAs($delegate)->post(route('topics.lock', $topicA))->assertRedirect();
    expect($topicA->fresh()->status)->toBe('locked');

    $this->actingAs($delegate)->get(route('topics.show', $topicB))
        ->assertOk()->assertDontSee('action="'.route('topics.lock', $topicB).'"', false);
    $this->actingAs($delegate)->post(route('topics.lock', $topicB))->assertForbidden();
    expect($topicB->fresh()->status)->toBe('open');
});

it('gates the header Moderation link on bans.manage — the exact dashboard capability, not group slug', function () {
    // Seeded moderators hold bans.manage globally → link present (stock behaviour unchanged).
    $mod = Users::inGroups(['moderators']);
    $this->actingAs($mod)->get(route('forums.index'))
        ->assertOk()->assertSee('href="'.route('moderation.dashboard').'"', false);

    // The old ghost: a moderators-group member whose bans.manage was revoked (NEVER is absolute) saw the
    // link and 403'd on click. Now: no link, and the route still refuses (the pair).
    $revoked = Users::inGroups(['moderators']);
    AclEntry::create([
        'permission_key' => 'bans.manage',
        'holder_type' => 'user', 'holder_id' => $revoked->getKey(),
        'scope_type' => Scope::global()->type, 'scope_id' => null,
        'value' => PermissionValue::Never->value,
    ]);
    app(PermissionResolver::class)->flushMemo();
    $this->actingAs($revoked)->get(route('forums.index'))
        ->assertOk()->assertDontSee('href="'.route('moderation.dashboard').'"', false);
    $this->actingAs($revoked)->get(route('moderation.dashboard'))->assertForbidden();

    // The inverse ghost: a delegated bans.manage holder (no staff group) never saw the link despite the
    // dashboard admitting them. Now: link present, route admits (the pair).
    $delegate = Users::inGroups(['members', 'tl1']);
    AclEntry::create([
        'permission_key' => 'bans.manage',
        'holder_type' => 'user', 'holder_id' => $delegate->getKey(),
        'scope_type' => Scope::global()->type, 'scope_id' => null,
        'value' => PermissionValue::Allow->value,
    ]);
    app(PermissionResolver::class)->flushMemo();
    $this->actingAs($delegate)->get(route('forums.index'))
        ->assertOk()->assertSee('href="'.route('moderation.dashboard').'"', false);
    $this->actingAs($delegate)->get(route('moderation.dashboard'))->assertOk();
});

it('mirrors the merge rank guard: no Merge trigger where MergeTopicsService would refuse "outranked"', function () {
    $forum = tmvForum('tmv-merge');
    $posts = app(PostService::class);
    $mod = Users::inGroups(['moderators']);

    // Member-authored: the mod out-ranks the author → trigger present.
    $memberTopic = $posts->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'Member thread', 'tiptap_json', Content::doc('op'));
    $this->actingAs($mod)->get(route('topics.show', $memberTopic))
        ->assertOk()->assertSee('dusk="topic-merge"', false);

    // Admin-authored: the service throws "outranked" on every attempt → trigger hidden (was the ghost).
    $adminTopic = $posts->createTopic(Users::inGroups(['admins']), $forum, 'Admin thread', 'tiptap_json', Content::doc('op'));
    $this->actingAs($mod)->get(route('topics.show', $adminTopic))
        ->assertOk()->assertDontSee('dusk="topic-merge"', false);

    // An admin out-ranks everyone → trigger present even on another admin's topic.
    $admin = Users::inGroups(['admins']);
    $this->actingAs($admin)->get(route('topics.show', $adminTopic))
        ->assertOk()->assertSee('dusk="topic-merge"', false);
});

it('gates the GET posts.edit page exactly like the embedded editor (update policy)', function () {
    $forum = tmvForum('tmv-edit');
    $posts = app(PostService::class);
    $author = Users::inGroups(['members', 'tl1']);
    $topic = $posts->createTopic($author, $forum, 'Editable', 'tiptap_json', Content::doc('op'));
    $post = $topic->posts()->firstOrFail();

    $this->actingAs($author)->get(route('posts.edit', $post))->assertOk();
    $this->actingAs(Users::inGroups(['members', 'tl1']))->get(route('posts.edit', $post))->assertForbidden();
});

it('only links the moderation dashboard from queue/recycle-bin for a viewer the dashboard admits', function () {
    $member = Users::inGroups(['members', 'tl1']);
    $this->actingAs($member)->get(route('moderation.recycle-bin'))
        ->assertOk()->assertDontSee('href="'.route('moderation.dashboard').'"', false);
    $this->actingAs($member)->get(route('moderation.queue'))
        ->assertOk()->assertDontSee('href="'.route('moderation.dashboard').'"', false);

    $mod = Users::inGroups(['moderators']);
    $this->actingAs($mod)->get(route('moderation.recycle-bin'))
        ->assertOk()->assertSee('href="'.route('moderation.dashboard').'"', false);
    $this->actingAs($mod)->get(route('moderation.queue'))
        ->assertOk()->assertSee('href="'.route('moderation.dashboard').'"', false);
});
