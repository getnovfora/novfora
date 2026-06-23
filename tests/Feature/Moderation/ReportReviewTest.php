<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubService;
use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Models\WarningType;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| F4 — the reported-post review UX. Each open report card shows the reported post's rendered body, the reporter
| + reason, a permalink to the post in its topic, and the moderator actions the viewer is permitted (each gated
| via the EXISTING policies/services). The enriched review renders ONLY when the viewer may see the forum, so a
| delegated bans.manage holder never leaks a private-club post body — and only permitted actions are shown.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

/**
 * A forum + an approved topic/post by $author carrying a unique body token, plus an open report on the post.
 *
 * @return array{forum: Forum, author: User, reporter: User, post: Post, report: Report}
 */
function reportedPost(string $bodyToken, ?Forum $forum = null, ?User $author = null): array
{
    $forum ??= Forum::create(['slug' => 'rr-'.substr(uniqid(), -8), 'title' => 'RR Forum', 'type' => 'forum']);
    $author ??= Users::inGroups(['members', 'tl2'], ['username' => 'rrauthor'.substr(uniqid(), -6)]);
    $reporter = Users::inGroups(['members'], ['username' => 'rrreporter'.substr(uniqid(), -6)]);

    $topic = app(PostService::class)->createTopic($author, $forum, 'Reported topic title', 'markdown', ['source' => $bodyToken]);
    $post = $topic->posts()->firstOrFail();

    $report = Report::create([
        'reporter_id' => $reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => 'this looks like spam',
        'status' => 'open',
    ]);

    return compact('forum', 'author', 'reporter', 'post', 'report');
}

it('shows the reported post body, the reporter + reason, and a permalink to the post in its topic', function () {
    ['post' => $post, 'reporter' => $reporter] = reportedPost('RRVISIBLEBODY');

    $this->actingAs(Users::inGroups(['moderators']))
        ->get(route('moderation.reports'))->assertOk()
        ->assertSee('RRVISIBLEBODY')                  // the rendered (sanitised) post body excerpt
        ->assertSee('this looks like spam')           // the report reason
        ->assertSee($reporter->username)              // the reporter
        ->assertSee('#post-'.$post->id, false);       // a permalink anchoring the exact reported post
});

it('renders every permitted moderator action for an admin (existing routes/policies)', function () {
    expect(WarningType::where('is_active', true)->exists())->toBeTrue(); // the seed provides active types
    reportedPost('RRADMINBODY');

    $this->actingAs(Users::inGroups(['admins']))
        ->get(route('moderation.reports'))->assertOk()
        ->assertSee('Lock')           // topic.moderate
        ->assertSee('Edit post')      // PostPolicy::update
        ->assertSee('Delete post')    // PostPolicy::delete
        ->assertSee('Delete topic')   // topic.moderate
        ->assertSee('Warn author');   // bans.manage + rank guard + an active warning type exists
});

it('hides the topic/post actions from a delegated bans.manage holder who cannot moderate', function () {
    ['post' => $post] = reportedPost('RRGATEBODY');

    // A reports-only reviewer: bans.manage granted (so the dashboard loads) + forum.view as a plain member (so
    // they may SEE the post), but NO topic.moderate and no post.edit/delete.any — the actions must NOT render.
    $reviewer = Users::inGroups(['members']);
    Acl::make()->grant($reviewer, 'bans.manage', Scope::global(), PermissionValue::Allow);
    app(PermissionResolver::class)->flushMemo();

    $this->actingAs($reviewer)->get(route('moderation.reports'))->assertOk()
        ->assertSee('RRGATEBODY')          // can see the post body (forum.view as a member) ...
        ->assertDontSee('Delete topic')    // ... but cannot moderate the topic ...
        ->assertDontSee('Delete post')     // ... nor delete the post ...
        ->assertDontSee('Lock');           // ... nor lock it.
});

it('does not leak a private-club post body to a delegated reviewer who is not a member or staff', function () {
    $owner = Users::inGroups(['members', 'tl2']);
    $club = app(ClubService::class)->create($owner, ['name' => 'Secret Club '.substr(uniqid(), -6), 'privacy' => 'private']);
    $forum = $club->forum;
    $topic = app(PostService::class)->createTopic($owner, $forum, 'Club topic', 'markdown', ['source' => 'CLUBONLYBODY']);
    $post = $topic->posts()->firstOrFail();
    Report::create(['reporter_id' => $owner->id, 'reportable_type' => Post::class, 'reportable_id' => $post->id, 'reason' => 'club report', 'status' => 'open']);

    // A delegated bans.manage holder who is NOT a club member and NOT staff: clubContentVisibleTo + forum.view
    // both fail, so they see the report exists but NEVER its body.
    $reviewer = Users::inGroups(['members']);
    Acl::make()->grant($reviewer, 'bans.manage', Scope::global(), PermissionValue::Allow);
    app(PermissionResolver::class)->flushMemo();

    $this->actingAs($reviewer)->get(route('moderation.reports'))->assertOk()
        ->assertSee('club report')         // the bare report (header + reason) is still actionable ...
        ->assertDontSee('CLUBONLYBODY');   // ... but the private-club body is never leaked.
});
