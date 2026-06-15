<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Accessibility\AccessibilityAuditor;
use App\Forum\PostService;
use App\Models\Forum;
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
