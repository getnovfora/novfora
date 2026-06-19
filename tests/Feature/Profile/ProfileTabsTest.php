<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\User;
use App\Permissions\PermissionResolver;
use App\Permissions\VisibleForumIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| BUG-017: the public profile gains Activity / Posts / About tabs (query-param driven, server-rendered). The
| Posts and Activity tabs are CORRECTNESS-LOAD-BEARING: they must respect the VIEWER's per-forum visibility,
| so a post/activity in a forum the viewer cannot see never leaks onto a public profile.
| BUG-018: the staff-tools "Delete account" card is no longer front-and-centre under the hero — it is a
| collapsed <details> below the tabs, still gated to staff who can force-delete.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
    $this->seed();
});

/** A public + a secret forum, each with one topic by $author; the secret one is denied to $viewer. */
function profileForumsWithSecret(User $author, User $viewer): void
{
    $public = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $secret = Forum::create(['slug' => 'secret', 'title' => 'Secret', 'type' => 'forum']);
    $posts = app(PostService::class);
    $posts->createTopic($author, $public, 'Public topic', 'tiptap_json', Content::doc('a public post body'));
    $posts->createTopic($author, $secret, 'Secret topic', 'tiptap_json', Content::doc('a secret post body'));

    AclEntry::create([
        'permission_key' => 'forum.view', 'holder_type' => 'user', 'holder_id' => $viewer->id,
        'scope_type' => 'forum', 'scope_id' => $secret->id, 'value' => -1, // NEVER, this viewer only
    ]);
    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
}

it('renders Activity / Posts / About tabs on a profile (BUG-017)', function () {
    $user = User::factory()->create(['username' => 'tabby']);

    $html = $this->get(route('profiles.show', $user))->assertOk()->getContent();

    expect($html)->toContain('dusk="profile-tabs"')
        ->toContain('Activity')->toContain('Posts')->toContain('About');
});

it('the Posts tab lists posts the viewer may see and hides forums they cannot (BUG-017)', function () {
    $author = Users::inGroups(['members', 'tl1'], ['username' => 'poster']);
    $viewer = Users::inGroups(['members', 'tl1']);
    profileForumsWithSecret($author, $viewer);

    $html = $this->actingAs($viewer)->get(route('profiles.show', [$author, 'tab' => 'posts']))->assertOk()->getContent();

    expect($html)->toContain('dusk="profile-posts"')
        ->toContain('Public topic')
        ->and($html)->not->toContain('Secret topic');
});

it('the Activity tab respects the same per-forum visibility (BUG-017)', function () {
    $author = Users::inGroups(['members', 'tl1'], ['username' => 'doer']);
    $viewer = Users::inGroups(['members', 'tl1']);
    profileForumsWithSecret($author, $viewer);

    $html = $this->actingAs($viewer)->get(route('profiles.show', [$author, 'tab' => 'activity']))->assertOk()->getContent();

    expect($html)->toContain('dusk="profile-activity"')
        ->toContain('Public topic')
        ->and($html)->not->toContain('Secret topic');
});

it('hides the staff-tools card from a non-staff viewer (BUG-018)', function () {
    $subject = User::factory()->create(['username' => 'subject']);
    $member = Users::inGroups(['members']);

    $html = $this->actingAs($member)->get(route('profiles.show', $subject))->assertOk()->getContent();

    expect($html)->not->toContain('dusk="staff-tools"')
        ->and($html)->not->toContain('staff-delete-account');
});

it('shows staff tools as a collapsed details BELOW the tabs, not front-and-centre (BUG-018)', function () {
    $subject = User::factory()->create(['username' => 'subject']);
    $staff = Users::inGroups(['moderators']); // has bans.manage → canForceDelete a plain member

    $html = $this->actingAs($staff)->get(route('profiles.show', $subject))->assertOk()->getContent();

    expect($html)->toContain('dusk="staff-tools"')
        ->toContain('<details')               // collapsed container…
        ->and($html)->not->toContain('<details open'); // …and not expanded by default

    // The tabs render before the staff-tools card — it is no longer the first element under the hero.
    expect(strpos($html, 'dusk="profile-tabs"'))->toBeLessThan(strpos($html, 'dusk="staff-tools"'));
});
