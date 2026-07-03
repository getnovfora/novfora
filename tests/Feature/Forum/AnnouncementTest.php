<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\AnnouncementService;
use App\Forum\PostService;
use App\Models\AclEntry;
use App\Models\AnnouncementDismissal;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Topic;
use App\Permissions\PermissionResolver;
use App\Permissions\VisibleForumIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| U4 (NOV-102) — the announcement topic type: dismissible, criteria-targeted banners. The load-bearing
| assertions are the two visibility fences (a targeted announcement must never reach a user outside its
| audience, nor anyone without access to its forum) and the mod-gate contract PAIR (control shown ⇔ POST
| works; hidden ⇔ 403). Banner presence is asserted on the dismiss form's action URL — a banner-only marker
| — never a title substring (which can appear elsewhere on the page).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
    $this->seed();
});

function annForum(string $slug): Forum
{
    return Forum::create(['slug' => $slug, 'title' => ucfirst($slug), 'type' => 'forum']);
}

function makeAnnouncement(Forum $forum, ?array $groupIds = null, $expiresAt = null, string $title = 'Site-wide announcement'): Topic
{
    $topic = app(PostService::class)->createTopic(Users::inGroups(['moderators']), $forum, $title, 'tiptap_json', Content::doc('op'));
    $topic->update([
        'type' => 'announcement',
        'approved_state' => 'approved',
        'announcement_audience' => $groupIds === null ? null : ['groups' => $groupIds],
        'announcement_expires_at' => $expiresAt,
    ]);

    return $topic->fresh();
}

function annDenyForumView(int $groupId, int $forumId): void
{
    AclEntry::create([
        'permission_key' => 'forum.view', 'holder_type' => 'group', 'holder_id' => $groupId,
        'scope_type' => 'forum', 'scope_id' => $forumId, 'value' => -1, // NEVER (absolute)
    ]);
    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
}

it('publishes then retracts an announcement through the mod endpoint', function () {
    $forum = annForum('ann-pub');
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'A thread', 'tiptap_json', Content::doc('op'));
    $mod = Users::inGroups(['moderators']);

    $this->actingAs($mod)->post(route('topics.announce', $topic), ['expires_at' => now()->addWeek()->toDateTimeString()])->assertRedirect();
    $topic->refresh();
    expect($topic->isAnnouncement())->toBeTrue()
        ->and($topic->announcement_expires_at)->not->toBeNull();

    // Toggling again retracts it and clears the targeting/expiry so nothing stale is left behind.
    $this->actingAs($mod)->post(route('topics.announce', $topic))->assertRedirect();
    $topic->refresh();
    expect($topic->type)->toBe('normal')
        ->and($topic->announcement_audience)->toBeNull()
        ->and($topic->announcement_expires_at)->toBeNull();
});

it('hides the announce control from a plain member and 403s the forged POST (the contract pair)', function () {
    $forum = annForum('ann-gate');
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'A thread', 'tiptap_json', Content::doc('op'));

    $member = Users::inGroups(['members', 'tl1']);
    $this->actingAs($member)->get(route('topics.show', $topic))
        ->assertOk()->assertDontSee('action="'.route('topics.announce', $topic).'"', false);
    $this->actingAs($member)->post(route('topics.announce', $topic))->assertForbidden();
    expect($topic->fresh()->type)->toBe('normal');

    // The pair's positive half: a moderator sees the control.
    $mod = Users::inGroups(['moderators']);
    $this->actingAs($mod)->get(route('topics.show', $topic))
        ->assertOk()->assertSee('action="'.route('topics.announce', $topic).'"', false);
});

it('fences the banner to its audience: shown to an in-audience member, hidden from one outside it', function () {
    $forum = annForum('ann-aud');
    $vip = Group::create(['slug' => 'ann-vip', 'name' => 'VIP', 'type' => 'custom']);
    $topic = makeAnnouncement($forum, [(int) $vip->id]);

    $in = Users::inGroups(['members', 'tl1']);
    $in->groups()->attach($vip->id);
    $out = Users::inGroups(['members', 'tl1']);

    $this->actingAs($in)->get(route('forums.index'))
        ->assertOk()->assertSee('action="'.route('announcements.dismiss', $topic).'"', false);
    $this->actingAs($out)->get(route('forums.index'))
        ->assertOk()->assertDontSee('action="'.route('announcements.dismiss', $topic).'"', false);

    // The dismiss endpoint won't even confirm the announcement exists to a user outside its audience.
    $this->actingAs($out)->post(route('announcements.dismiss', $topic))->assertNotFound();
});

it('fences the banner on forum visibility even when the audience matches', function () {
    $forum = annForum('ann-hidden');
    $topic = makeAnnouncement($forum); // untargeted → the audience fence admits everyone
    $member = Users::inGroups(['members', 'tl1']);
    $svc = app(AnnouncementService::class);

    expect($svc->activeFor($member)->pluck('id'))->toContain($topic->id);

    // Revoke forum.view for the member's group → the forum fence must drop the announcement even though the
    // audience still matches (defence in depth: audience ⊄ forum access).
    annDenyForumView((int) Group::where('slug', 'members')->value('id'), (int) $forum->id);

    expect($svc->activeFor($member)->pluck('id'))->not->toContain($topic->id);
});

it('drops a dismissed announcement for the dismisser but keeps it for everyone else, idempotently', function () {
    $forum = annForum('ann-dismiss');
    $topic = makeAnnouncement($forum);
    $a = Users::inGroups(['members', 'tl1']);
    $b = Users::inGroups(['members', 'tl1']);

    $this->actingAs($a)->get(route('forums.index'))
        ->assertOk()->assertSee('action="'.route('announcements.dismiss', $topic).'"', false);

    $this->actingAs($a)->post(route('announcements.dismiss', $topic))->assertRedirect();
    $this->actingAs($a)->post(route('announcements.dismiss', $topic))->assertRedirect(); // idempotent
    expect(AnnouncementDismissal::where('user_id', $a->id)->where('topic_id', $topic->id)->count())->toBe(1);

    $this->actingAs($a)->get(route('forums.index'))
        ->assertOk()->assertDontSee('action="'.route('announcements.dismiss', $topic).'"', false);
    $this->actingAs($b)->get(route('forums.index'))
        ->assertOk()->assertSee('action="'.route('announcements.dismiss', $topic).'"', false);
});

it('does not surface an expired announcement but still surfaces a live one', function () {
    $member = Users::inGroups(['members', 'tl1']);
    $expired = makeAnnouncement(annForum('ann-exp'), null, now()->subMinute());
    $live = makeAnnouncement(annForum('ann-live'), null, now()->addDay());

    $ids = app(AnnouncementService::class)->activeFor($member)->pluck('id');
    expect($ids)->not->toContain($expired->id)
        ->and($ids)->toContain($live->id);
});

it('shows an untargeted announcement to a guest but never a targeted one', function () {
    $forum = annForum('ann-guest');
    $open = makeAnnouncement($forum, null, null, 'Open to all');
    $vip = Group::create(['slug' => 'ann-guest-vip', 'name' => 'VIP2', 'type' => 'custom']);
    $targeted = makeAnnouncement($forum, [(int) $vip->id], null, 'Members only');

    $ids = app(AnnouncementService::class)->activeFor(null)->pluck('id');
    expect($ids)->toContain($open->id)
        ->and($ids)->not->toContain($targeted->id);
});
