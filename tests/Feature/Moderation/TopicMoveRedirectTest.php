<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| U19 (NOV-116) — move-with-redirect. A mod move can leave a "moved" shadow topic in the source forum whose
| moved_to_topic_id points at the (moved) real topic, so its URL transitively-301s to it. A shadow is not
| itself movable (no shadow-of-a-shadow chains). Reuses TopicController's proven transitive-301 resolver.
*/

uses(RefreshDatabase::class);

it('leaves a redirecting shadow in the source forum when asked, and 301s it to the moved topic', function () {
    $this->seed();
    $src = Forum::create(['slug' => 'src', 'title' => 'Source', 'type' => 'forum']);
    $dst = Forum::create(['slug' => 'dst', 'title' => 'Dest', 'type' => 'forum']);
    $mod = Users::inGroups(['admins']); // holds topic.moderate globally
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl2'], ['username' => 'mv-op', 'email' => 'mv-op@t.test']), $src, 'Movable', 'markdown', ['source' => 'op']);

    $this->actingAs($mod)
        ->post(route('topics.move', $topic), ['forum_id' => $dst->id, 'leave_redirect' => 1])
        ->assertRedirect();

    expect($topic->fresh()->forum_id)->toBe($dst->id);

    $shadow = Topic::where('forum_id', $src->id)->where('status', 'moved')->first();
    expect($shadow)->not->toBeNull()
        ->and($shadow->moved_to_topic_id)->toBe($topic->id);

    // The shadow's URL transitively-301s to the real (moved) topic.
    $this->get(route('topics.show', $shadow))->assertRedirect(route('topics.show', $topic->getKey()));
});

it('does not leave a shadow when the redirect option is off', function () {
    $this->seed();
    $src = Forum::create(['slug' => 'src', 'title' => 'Source', 'type' => 'forum']);
    $dst = Forum::create(['slug' => 'dst', 'title' => 'Dest', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl2'], ['username' => 'mv2-op', 'email' => 'mv2@t.test']), $src, 'Plain move', 'markdown', ['source' => 'op']);

    $this->actingAs(Users::inGroups(['admins']))
        ->post(route('topics.move', $topic), ['forum_id' => $dst->id])
        ->assertRedirect();

    expect(Topic::where('status', 'moved')->count())->toBe(0)
        ->and($topic->fresh()->forum_id)->toBe($dst->id);
});

it('refuses to move a redirect shadow (no shadow-of-a-shadow chain)', function () {
    $this->seed();
    $src = Forum::create(['slug' => 'src', 'title' => 'Source', 'type' => 'forum']);
    $dst = Forum::create(['slug' => 'dst', 'title' => 'Dest', 'type' => 'forum']);
    $mod = Users::inGroups(['admins']);
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl2'], ['username' => 'mv3-op', 'email' => 'mv3@t.test']), $src, 'Chainable', 'markdown', ['source' => 'op']);

    $this->actingAs($mod)->post(route('topics.move', $topic), ['forum_id' => $dst->id, 'leave_redirect' => 1]);
    $shadow = Topic::where('status', 'moved')->firstOrFail();

    $this->actingAs($mod)->post(route('topics.move', $shadow), ['forum_id' => $dst->id])->assertStatus(422);
});
