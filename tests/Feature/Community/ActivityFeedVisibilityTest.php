<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\ActivityFeed;
use App\Forum\PostService;
use App\Models\AclEntry;
use App\Models\Activity;
use App\Models\Forum;
use App\Models\User;
use App\Permissions\PermissionResolver;
use App\Permissions\VisibleForumIds;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Batch 2026-06-21 · Branch 3 (ADR-0091): the activity feed never leaks a forum the viewer can't see —
| including an orphaned row from a hard-deleted forum — and a restricted viewer's feed isn't starved by a
| global window dominated by forums they can't see.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
    $this->seed();
});

function afvForum(string $slug): Forum
{
    return Forum::create(['slug' => $slug, 'title' => ucfirst($slug), 'type' => 'forum']);
}

/** Deny forum.view on $forum for one viewer (a user-holder NEVER) → the forum is restricted to them. */
function afvDeny(User $viewer, Forum $forum): void
{
    AclEntry::create([
        'permission_key' => 'forum.view', 'holder_type' => 'user', 'holder_id' => $viewer->id,
        'scope_type' => 'forum', 'scope_id' => $forum->id, 'value' => -1,
    ]);
}

/** Whether $viewer's feed contains a row authored by the given display name. */
function afvFeedHasActor(User $viewer, string $displayName): bool
{
    VisibleForumIds::flush();
    app(PermissionResolver::class)->flushMemo();

    return in_array($displayName, array_map(
        fn ($i) => $i->actor?->display_name,
        app(ActivityFeed::class)->for($viewer),
    ), true);
}

it('hides a hard-deleted forum\'s orphaned activity from non-staff, but a see-all staff viewer may see it', function () {
    afvForum('visible');
    $private = afvForum('private');

    app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1'], ['display_name' => 'PublicPoster']), Forum::where('slug', 'visible')->firstOrFail(), 'Visible topic', 'tiptap_json', Content::doc('b'));
    $secret = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1'], ['display_name' => 'SecretPoster']), $private, 'Secret topic', 'tiptap_json', Content::doc('b'));

    // Simulate the HARD forum delete: the subject is gone and the row's scope_forum_id is nulled (nullOnDelete).
    $secret->forceDelete();
    Activity::where('scope_forum_id', $private->id)->update(['scope_forum_id' => null]);
    Cache::flush();

    // A guest and a regular member must NOT see the orphaned (now null-scoped, subject-gone) row...
    expect(afvFeedHasActor(User::guest(), 'SecretPoster'))->toBeFalse()
        ->and(afvFeedHasActor(Users::inGroups(['members', 'tl1']), 'SecretPoster'))->toBeFalse();

    // ...but a staff/admin viewer who currently sees every forum still may.
    expect(afvFeedHasActor(Users::inGroups(['admins']), 'SecretPoster'))->toBeTrue();
});

it('excludes a topic MOVED into a restricted forum after the activity was logged', function () {
    $open = afvForum('open');
    $restricted = afvForum('restricted');
    $author = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($author, $open, 'Movable topic', 'tiptap_json', Content::doc('b'));

    // Move the topic into the restricted forum — the activity's FROZEN scope stays 'open'.
    $topic->update(['forum_id' => $restricted->id]);

    $viewer = Users::inGroups(['members', 'tl1']);
    afvDeny($viewer, $restricted);
    Cache::flush();
    VisibleForumIds::flush();
    app(PermissionResolver::class)->flushMemo();

    $titles = array_map(fn ($i) => $i->title(), app(ActivityFeed::class)->for($viewer));
    expect($titles)->not->toContain('Movable topic'); // current-state pass: live forum is restricted
});

it('keeps a restricted viewer\'s feed non-empty when their visible forum is low-traffic (underflow fix)', function () {
    $busy = afvForum('busy');     // the viewer CANNOT see this one
    $quiet = afvForum('quiet');   // the viewer's only extra forum, low-traffic
    $author = Users::inGroups(['members', 'tl1']);

    // One OLD activity in the quiet (visible) forum…
    app(PostService::class)->createTopic($author, $quiet, 'Quiet topic', 'tiptap_json', Content::doc('b'));

    // …then > WINDOW (100) NEWER activities in the busy (invisible) forum, so a GLOBAL window would be all-busy.
    $busyTopic = app(PostService::class)->createTopic($author, $busy, 'Busy topic', 'tiptap_json', Content::doc('b'));
    $now = now();
    $rows = [];
    for ($i = 0; $i < 110; $i++) {
        $rows[] = [
            'actor_id' => $author->id, 'verb' => Activity::VERB_TOPIC_CREATED,
            'subject_type' => $busyTopic->getMorphClass(), 'subject_id' => $busyTopic->id,
            'object_type' => null, 'object_id' => null, 'scope_forum_id' => $busy->id, 'created_at' => $now,
        ];
    }
    Activity::insert($rows);

    $viewer = Users::inGroups(['members', 'tl1']);
    afvDeny($viewer, $busy);
    Cache::flush();
    VisibleForumIds::flush();
    app(PermissionResolver::class)->flushMemo();

    $titles = array_map(fn ($i) => $i->title(), app(ActivityFeed::class)->for($viewer));
    expect($titles)->toContain('Quiet topic')      // the visible forum's row survives the busy flood
        ->and($titles)->not->toContain('Busy topic');
});

it('makes the profile Activity tab honour general.activity_feed_limit, not a fixed 20', function () {
    $forum = afvForum('general');
    $subject = Users::inGroups(['members', 'tl1']);
    foreach (range(1, 8) as $i) {
        app(PostService::class)->createTopic($subject, $forum, "Topic {$i}", 'tiptap_json', Content::doc("b{$i}"));
    }

    app(Settings::class)->set('general.activity_feed_limit', 5);
    Cache::flush();
    VisibleForumIds::flush();

    $viewer = Users::inGroups(['members', 'tl1']);
    $response = $this->actingAs($viewer)
        ->get(route('profiles.show', $subject).'?tab=activity')
        ->assertOk();

    expect($response->viewData('activity'))->toHaveCount(5); // the configured 5, not the old hardcoded 20
});
