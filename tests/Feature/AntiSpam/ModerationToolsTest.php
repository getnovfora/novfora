<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\PostRateLimiter;
use App\AntiSpam\SpamCleaner;
use App\Forum\PostService;
use App\Models\Ban;
use App\Models\Forum;
use App\Models\Report;
use App\Models\Topic;
use App\Permissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| The reactive moderation toolkit (security §3 / ADR-0007 §2.4): reports → dashboard, bans (enforced
| through the permission engine), the Spam Cleaner, and per-trust post rate limiting.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

function toolForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

it('lets a member report a post and a moderator resolve it', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['moderators']), toolForum(), 'T', 'tiptap_json', Content::doc('op'));
    $post = $topic->posts()->firstOrFail();

    $this->actingAs(Users::inGroups(['members']))->post(route('reports.store'), ['post_id' => $post->id, 'reason' => 'this is spam'])->assertRedirect();
    $report = Report::firstOrFail();
    expect($report->status)->toBe('open');

    $this->actingAs(Users::inGroups(['moderators']))->get(route('moderation.reports'))->assertOk()->assertSee('this is spam');
    $this->actingAs(Users::inGroups(['moderators']))->post(route('reports.resolve', $report), ['action' => 'resolved'])->assertRedirect();
    expect($report->fresh()->status)->toBe('resolved');
});

it('forbids a non-staff member from the reports dashboard', function () {
    $this->actingAs(Users::inGroups(['members']))->get(route('moderation.reports'))->assertForbidden();
});

it('lets staff ban a user — blocking them through the engine — and lift it', function () {
    $forum = toolForum();
    $target = Users::inGroups(['members', 'tl1']);
    expect($target->canDo('post.create', $forum->permissionScope()))->toBeTrue();

    $this->actingAs(Users::inGroups(['moderators']))
        ->post(route('bans.store'), ['type' => 'user', 'user_id' => $target->id, 'reason' => 'spam'])->assertRedirect();

    expect($target->fresh()->status)->toBe('banned');
    app(PermissionResolver::class)->flushMemo();
    expect($target->fresh()->canDo('post.create', $forum->permissionScope()))->toBeFalse(); // ban precedes the ACL

    $ban = Ban::where('user_id', $target->id)->firstOrFail();
    $this->actingAs(Users::inGroups(['moderators']))->delete(route('bans.destroy', $ban))->assertRedirect();
    expect($target->fresh()->status)->toBe('active');
});

it('forbids a non-staff member from banning', function () {
    $target = Users::inGroups(['members']);
    $this->actingAs(Users::inGroups(['members']))
        ->post(route('bans.store'), ['type' => 'user', 'user_id' => $target->id])->assertForbidden();
});

it('spam-cleans an account: soft-deletes its content and bans it', function () {
    $spammer = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($spammer, toolForum(), 'Spammy topic', 'tiptap_json', Content::doc('buy my stuff'));

    app(SpamCleaner::class)->clean(Users::inGroups(['moderators']), $spammer, 'spam');

    expect(Topic::find($topic->id))->toBeNull();                  // soft-deleted (recoverable)
    expect(Topic::withTrashed()->find($topic->id))->not->toBeNull();
    expect($spammer->fresh()->status)->toBe('banned');
    expect(Ban::where('user_id', $spammer->id)->where('type', 'user')->exists())->toBeTrue();
});

it('lets staff trigger the Spam Cleaner via the route', function () {
    $spammer = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($spammer, toolForum(), 'X', 'tiptap_json', Content::doc('op'));

    $this->actingAs(Users::inGroups(['moderators']))->post(route('moderation.spam-clean', $spammer))->assertRedirect();

    expect($spammer->fresh()->status)->toBe('banned');
    expect(Topic::find($topic->id))->toBeNull();
});

it('rate-limits posting per trust tier', function () {
    config(['hearth.antispam.rate_limits' => ['tl0' => 2, 'default' => 20]]);
    $tl0 = Users::inGroups(['members', 'tl0']);
    $limiter = app(PostRateLimiter::class);

    expect($limiter->attempt($tl0))->toBeTrue();   // 1
    expect($limiter->attempt($tl0))->toBeTrue();   // 2
    expect($limiter->attempt($tl0))->toBeFalse();  // 3rd exceeds the TL0 cap of 2
});
