<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\IgnoreService;
use App\Messaging\ConversationService;
use App\Messaging\PmException;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Member tool 2.2 — ignore / block. An IGNORE edge hides the target's posts (never a staff member's) and
| blocks their PMs (enforced in ConversationService — proven here end-to-end from IgnoreService).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('ignores and un-ignores a member', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();
    $svc = app(IgnoreService::class);

    expect($svc->ignore($me, $them))->toBeTrue()
        ->and($svc->ignores($me, $them))->toBeTrue()
        ->and($svc->ignoredIds($me))->toBe([$them->id]);

    expect($svc->unignore($me, $them))->toBeTrue()
        ->and($svc->ignores($me, $them))->toBeFalse();
});

it('refuses a self-ignore (hard, never permission-liftable)', function () {
    $me = User::factory()->create();
    app(IgnoreService::class)->ignore($me, $me);
})->throws(InvalidArgumentException::class);

it('writes the IGNORE edge that ConversationService uses to block PMs', function () {
    $blocker = User::factory()->create();
    $sender = Users::inGroups(['moderators']); // has pm.send

    app(IgnoreService::class)->ignore($blocker, $sender); // blocker ignores the sender

    // The only recipient ignores the sender → no valid recipients → refused (existing PM-block behaviour).
    expect(fn () => app(ConversationService::class)->startConversation($sender, [$blocker->id], null, 'markdown', ['source' => 'hi']))
        ->toThrow(PmException::class);
});

it('toggles ignore through the profile button', function () {
    $me = Users::inGroups(['members']);
    $them = User::factory()->create();

    Livewire::actingAs($me)
        ->test('community.ignore-button', ['userId' => $them->id])
        ->call('toggle')
        ->assertSet('ignoring', true);

    expect(app(IgnoreService::class)->ignores($me, $them->fresh()))->toBeTrue();
});

it('collapses a post from an ignored member on the topic page', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    $ignored = User::factory()->create();
    $topic = Topic::create(['slug' => 't', 'title' => 'T', 'forum_id' => $forum->id, 'user_id' => $ignored->id, 'last_posted_at' => now()]);
    Post::create(['topic_id' => $topic->id, 'user_id' => $ignored->id, 'body_format' => 'tiptap_json', 'body_canonical' => [], 'body_html_cache' => '<p>hidden</p>', 'body_text' => 'hidden', 'position' => 1, 'approved_state' => 'approved']);

    $viewer = Users::inGroups(['members']);
    app(IgnoreService::class)->ignore($viewer, $ignored);

    $this->actingAs($viewer)->get(route('topics.show', $topic))->assertOk()->assertSee('You ignore this member');
});

it('NEVER collapses a staff member’s post, even when ignored', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    $mod = Users::inGroups(['moderators']); // staff
    $topic = Topic::create(['slug' => 't', 'title' => 'T', 'forum_id' => $forum->id, 'user_id' => $mod->id, 'last_posted_at' => now()]);
    Post::create(['topic_id' => $topic->id, 'user_id' => $mod->id, 'body_format' => 'tiptap_json', 'body_canonical' => [], 'body_html_cache' => '<p>mod post</p>', 'body_text' => 'mod', 'position' => 1, 'approved_state' => 'approved']);

    $viewer = Users::inGroups(['members']);
    app(IgnoreService::class)->ignore($viewer, $mod);

    $this->actingAs($viewer)->get(route('topics.show', $topic))->assertOk()->assertDontSee('You ignore this member');
});

it('shows the ignore list and unignores from settings', function () {
    $me = Users::inGroups(['members']);
    $them = User::factory()->create(['username' => 'noisybob']);
    app(IgnoreService::class)->ignore($me, $them);

    $this->actingAs($me)->get(route('settings.ignore-list'))->assertOk()->assertSee('noisybob');

    Livewire::actingAs($me)->test('settings.ignore-list')->call('unignore', $them->id);
    expect(UserRelationship::where('user_id', $me->id)->where('type', UserRelationship::TYPE_IGNORE)->count())->toBe(0);
});
