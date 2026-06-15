<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Broadcasting\ChannelAuthorizer;
use App\Clubs\ClubService;
use App\Forum\PostService;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Forum;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Phase 4 · M4.2 (APEX) — broadcast channel authorization is the websocket no-leak fence. A user may only
| subscribe to a channel for content they can already view: the SAME permission-engine + club-visibility +
| participant checks the HTTP surfaces use. These tests are the authoritative proof; the socket round-trip
| itself is NOT validated against a real Reverb (ADR-0061).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function authorizer(): ChannelAuthorizer
{
    return app(ChannelAuthorizer::class);
}

function freshAclChan(): void
{
    app(PermissionResolver::class)->flushMemo();
    Cache::flush();
}

// ── notifications.{userId}: owner only ───────────────────────────────────────────────────────────────────

it('authorizes the notifications channel only for its owner', function () {
    $owner = Users::inGroups(['members', 'tl1'], ['email' => 'own@chan.test']);
    $other = Users::inGroups(['members', 'tl1'], ['email' => 'other@chan.test']);

    expect(authorizer()->ownsNotificationStream($owner, (int) $owner->id))->toBeTrue();
    expect(authorizer()->ownsNotificationStream($other, (int) $owner->id))->toBeFalse();
});

// ── thread.{topicId}: forum.view + club gate ─────────────────────────────────────────────────────────────

it('authorizes a normal-forum thread for a member who can view it', function () {
    $acl = Acl::make();
    $member = $acl->user(['members', 'tl1']);

    expect(authorizer()->canViewThread($member, (int) $acl->thread->id))->toBeTrue();
});

it('denies a thread when forum.view is NEVER for the viewer', function () {
    $acl = Acl::make();
    $acl->grant('members', 'forum.view', $acl->forumScope, V::Never);
    freshAclChan();
    $member = $acl->user(['members', 'tl1']);

    expect(authorizer()->canViewThread($member, (int) $acl->thread->id))->toBeFalse();
});

it('fails closed for an unknown thread id', function () {
    $member = Users::inGroups(['members', 'tl1'], ['email' => 'unknown@chan.test']);

    expect(authorizer()->canViewThread($member, 999999))->toBeFalse();
});

it('denies a hidden-club thread to a non-member but allows an active member and staff', function () {
    $owner = Users::inGroups(['members', 'tl3'], ['email' => 'club-own@chan.test']);
    $club = app(ClubService::class)->create($owner, ['name' => 'Secret', 'privacy' => 'private', 'is_listed' => false]);
    $clubForum = Forum::where('club_id', $club->id)->firstOrFail();
    $topic = app(PostService::class)->createTopic($owner, $clubForum, 'Secret', 'tiptap_json', Content::doc('members only'));
    freshAclChan();

    $outsider = Users::inGroups(['members', 'tl1'], ['email' => 'out@chan.test']);
    $staff = Users::inGroups(['admins'], ['email' => 'staff@chan.test']);

    // A non-member can hold global forum.view=ALLOW yet must NEVER receive the club thread — the club gate denies.
    expect(authorizer()->canViewThread($outsider, (int) $topic->id))->toBeFalse();
    expect(authorizer()->canViewThread($owner->fresh(), (int) $topic->id))->toBeTrue();
    expect(authorizer()->canViewThread($staff, (int) $topic->id))->toBeTrue();
});

// ── conversation.{conversationId}: active participant only (PMs outside the scope tree) ──────────────────

it('authorizes a conversation channel for an active participant only', function () {
    $alice = Users::inGroups(['members', 'tl1'], ['email' => 'alice@chan.test']);
    $bob = Users::inGroups(['members', 'tl1'], ['email' => 'bob@chan.test']);
    $outsider = Users::inGroups(['members', 'tl1'], ['email' => 'nope@chan.test']);

    $convo = Conversation::factory()->create();
    ConversationParticipant::factory()->create(['conversation_id' => $convo->id, 'user_id' => $alice->id]);
    ConversationParticipant::factory()->create(['conversation_id' => $convo->id, 'user_id' => $bob->id]);

    expect(authorizer()->canViewConversation($alice, (int) $convo->id))->toBeTrue();
    expect(authorizer()->canViewConversation($outsider, (int) $convo->id))->toBeFalse();
});

it('denies a conversation channel to a soft-left participant', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'left@chan.test']);
    $convo = Conversation::factory()->create();
    ConversationParticipant::factory()->left()->create(['conversation_id' => $convo->id, 'user_id' => $user->id]);

    expect(authorizer()->canViewConversation($user, (int) $convo->id))->toBeFalse();
});

it('fails closed for an unknown conversation id', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'unk-convo@chan.test']);

    expect(authorizer()->canViewConversation($user, 424242))->toBeFalse();
});
