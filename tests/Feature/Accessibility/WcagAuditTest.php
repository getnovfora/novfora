<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Accessibility\AccessibilityAuditor;
use App\Clubs\ClubService;
use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Report;
use App\Models\Tag;
use App\Search\SavedSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| WCAG 2.1 AA page gate (Wave 8.2). Render the high-traffic surfaces through the real layout and assert the
| deterministic auditor finds ZERO violations. This is the automated half; the manual checklist
| (docs/architecture/accessibility.md) covers contrast/focus/screen-reader, which static HTML can't prove.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** Audit a rendered response and fail with the readable finding list if anything is flagged. */
function auditResponse(TestResponse $response): void
{
    $response->assertOk();
    $findings = app(AccessibilityAuditor::class)->audit($response->getContent() ?: '');
    $labels = array_map(fn ($f) => $f->label(), $findings);

    expect($findings)->toBe([], 'Accessibility findings:'.PHP_EOL.implode(PHP_EOL, $labels));
}

it('the board index is accessible', function () {
    auditResponse($this->get(route('forums.index')));
});

it('the search page (with facets + save form) is accessible', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    $member = Users::inGroups(['members', 'tl1']);
    app(PostService::class)->createTopic($member, $forum, 'Findable', 'tiptap_json', Content::doc('searchable wibble body'));

    auditResponse($this->actingAs($member)->get(route('search.index', ['q' => 'wibble'])));
});

it('the empty-result search page is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('search.index', ['q' => 'nothingmatchesthisxyz'])));
});

it('the saved-searches page is accessible', function () {
    $member = Users::inGroups(['members']);
    app(SavedSearchService::class)->save($member, 'My search', 'wibble', 'q=wibble');

    auditResponse($this->actingAs($member)->get(route('saved-searches.index')));
});

it('a topic page is accessible', function () {
    $forum = Forum::create(['slug' => 'g2', 'title' => 'G2', 'type' => 'forum']);
    $member = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($member, $forum, 'A readable topic', 'tiptap_json', Content::doc('the opening post body here'));

    auditResponse($this->actingAs($member)->get(route('topics.show', $topic)));
});

it('the tags index is accessible', function () {
    auditResponse($this->get(route('tags.index')));
});

it('the login page is accessible', function () {
    auditResponse($this->get(route('login')));
});

it('the registration page is accessible', function () {
    auditResponse($this->get(route('register')));
});

it('a forum listing page is accessible', function () {
    $forum = Forum::create(['slug' => 'g3', 'title' => 'G3', 'type' => 'forum']);
    $member = Users::inGroups(['members', 'tl1']);
    app(PostService::class)->createTopic($member, $forum, 'Listed topic', 'tiptap_json', Content::doc('body in the listing'));

    auditResponse($this->actingAs($member)->get(route('forums.show', $forum)));
});

it('the create-topic form is accessible', function () {
    $forum = Forum::create(['slug' => 'g4', 'title' => 'G4', 'type' => 'forum']);
    $member = Users::inGroups(['members', 'tl1']);

    auditResponse($this->actingAs($member)->get(route('topics.create', $forum)));
});

it('the appearance settings form is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('settings.appearance')));
});

it('the edit-profile form is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('settings.profile')));
});

it('a member profile page is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('profiles.show', $member)));
});

it('the members index is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('members.index')));
});

// ── Phase 5 (P5.2): the Phase 3/4 + remaining user-facing surfaces the original gate did not cover ────────

it('the top-members leaderboard is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('members.top')));
});

it('the activity home feed is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('home')));
});

it('the trending page is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('trending.index')));
});

it('the what-is-new page is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('whats-new')));
});

it('the saved/bookmarks page is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('saved.index')));
});

it('the notifications page is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('notifications.index')));
});

it('the notification settings page is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('settings.notifications')));
});

it('the preferences settings page is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('settings.preferences')));
});

it('the PM inbox + compose pages are accessible', function () {
    $member = Users::inGroups(['members', 'tl1']);
    auditResponse($this->actingAs($member)->get(route('pm.inbox')));
    auditResponse($this->actingAs($member)->get(route('pm.create')));
});

it('a tag page is accessible', function () {
    $tag = Tag::create(['name' => 'announcements', 'slug' => 'announcements', 'usage_count' => 0]);
    auditResponse($this->get(route('tags.show', $tag)));
});

// ── Clubs (Phase 4 · M1) ──────────────────────────────────────────────────────────────────────────────────

it('the clubs directory + create form are accessible', function () {
    $member = Users::inGroups(['members', 'tl2']);
    auditResponse($this->actingAs($member)->get(route('clubs.index')));
    auditResponse($this->actingAs($member)->get(route('clubs.create')));
});

it('a club page + its roster are accessible', function () {
    $owner = Users::inGroups(['members', 'tl2']);
    $club = app(ClubService::class)->create($owner, ['name' => 'Accessible Club', 'privacy' => 'public']);

    auditResponse($this->actingAs($owner)->get(route('clubs.show', $club)));
    auditResponse($this->actingAs($owner)->get(route('clubs.members', $club)));
});

it('the membership/tiers page is accessible', function () {
    $member = Users::inGroups(['members']);
    auditResponse($this->actingAs($member)->get(route('membership.index')));
});

// ── v1.x batch + targeted fixes (Wave 8.3): the new/changed staff surfaces ────────────────────────────────
// The board index (Info Center collapse + coloured who's-online + latest-activity author/topic — F5/F6) and
// the board listing (sub-boards latest-activity — F6) are already covered above (forums.index / forums.show).

it('the moderation control panel (dashboard, queue, reported-post review) is accessible', function () {
    $mod = Users::inGroups(['moderators']);
    auditResponse($this->actingAs($mod)->get(route('moderation.dashboard'))); // F3 width
    auditResponse($this->actingAs($mod)->get(route('moderation.queue')));      // F3 width

    // Seed an open report so the F4 review card (post excerpt + permalink + gated moderator actions) renders.
    $forum = Forum::create(['slug' => 'moda11y', 'title' => 'Mod A11y', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($mod, $forum, 'Reported topic', 'tiptap_json', Content::doc('a reported post body'));
    Report::create([
        'reporter_id' => $mod->id,
        'reportable_type' => Post::class,
        'reportable_id' => $topic->posts()->firstOrFail()->id,
        'reason' => 'spam',
        'status' => 'open',
    ]);
    auditResponse($this->actingAs($mod)->get(route('moderation.reports'))); // F4 review UX
});

it('the per-member admin management screen (trust/reputation/ban/warn — F2) is accessible', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins'])); // holds the F2 capability keys + 2FA
    $target = Users::inGroups(['members'], ['username' => 'a11ymember']);
    auditResponse($this->actingAs($admin)->get(route('admin.members.show', $target)));
});

it('the ACP analytics dashboard (sparkline charts + the accessible data table) is accessible', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    auditResponse($this->actingAs($admin)->get(route('admin.analytics')));
});
