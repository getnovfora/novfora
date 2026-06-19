<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Club;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Html;
use Tests\Support\Users;

/*
| Breadcrumb schema (theme T2): a breadcrumb root equals that section's own top-level nav label — there is
| no cross-section nesting. Trending / What's new / Clubs are top-level destinations, so they must NOT nest
| under a false "Forums" parent (BUG-013, BUG-021); Notifications was missing its trail entirely.
| Tech-debt follow-up: replace these hardcoded crumbs with a route-meta/ViewComposer generator.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('trending breadcrumb is a top-level "Trending", not nested under Forums (BUG-021)', function () {
    $trail = Html::breadcrumbTrail($this->get(route('trending.index'))->assertOk()->getContent());

    expect($trail)->toContain('Trending')->and($trail)->not->toContain('Forums');
});

it("what's-new breadcrumb is top-level, not nested under Forums (BUG-013)", function () {
    $member = Users::inGroups(['members']);

    $trail = Html::breadcrumbTrail($this->actingAs($member)->get(route('whats-new'))->assertOk()->getContent());

    expect($trail)->toContain("What's new")->and($trail)->not->toContain('Forums');
});

it('clubs index breadcrumb is top-level, not nested under Forums (BUG-013)', function () {
    $trail = Html::breadcrumbTrail($this->get(route('clubs.index'))->assertOk()->getContent());

    expect($trail)->toContain('Clubs')->and($trail)->not->toContain('Forums');
});

it('a club page roots its breadcrumb at Clubs, not Forums (BUG-013)', function () {
    $club = Club::factory()->public()->create(['name' => 'Astronomy']);

    $trail = Html::breadcrumbTrail($this->get(route('clubs.show', $club))->assertOk()->getContent());

    expect($trail)->toContain('Clubs')
        ->and($trail)->toContain('Astronomy')
        ->and($trail)->not->toContain('Forums');
});

it('notifications now has a breadcrumb trail (BUG-013)', function () {
    $member = Users::inGroups(['members']);

    $trail = Html::breadcrumbTrail($this->actingAs($member)->get(route('notifications.index'))->assertOk()->getContent());

    expect($trail)->toContain('Notifications');
});
