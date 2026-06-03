<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\WarningService;
use App\Forum\PostService;
use App\Models\Ban;
use App\Models\Forum;
use App\Models\Warning;
use App\Models\WarningType;
use App\Permissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Warnings / infractions (security §3): typed, point-weighted, time-decaying, with automated consequences at
| thresholds (moderate → temp-ban → ban), trust demotion, and the acknowledge-to-restore flow.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

function spamWarning(): WarningType
{
    return WarningType::where('slug', 'spam')->firstOrFail(); // 10 points, decays in 90 days
}

it('issues a warning that restricts the member and demotes their trust', function () {
    $target = Users::inGroups(['members', 'tl2'], ['trust_level' => 2]);

    app(WarningService::class)->issue(Users::inGroups(['moderators']), $target, spamWarning(), 'spamming');

    $target->refresh();
    expect(Warning::where('user_id', $target->id)->count())->toBe(1);
    expect($target->status)->toBe('pending');   // 10 ≥ moderate threshold (5) → restricted
    expect((int) $target->trust_level)->toBe(0); // 10 ≥ demotion points (10) → TL0
});

it('holds a restricted member’s posts in the queue', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $target = Users::inGroups(['members', 'tl2'], ['trust_level' => 2]);
    app(WarningService::class)->issue(Users::inGroups(['moderators']), $target, spamWarning());

    $topic = app(PostService::class)->createTopic($target->fresh(), $forum, 'T', 'tiptap_json', Content::doc('op'));

    expect($topic->posts()->firstOrFail()->approved_state)->toBe('pending');
});

it('bans the account once infraction points cross the ban threshold', function () {
    $target = Users::inGroups(['members', 'tl1']);
    $mod = Users::inGroups(['moderators']);

    foreach (range(1, 3) as $i) { // 3 × 10 points = 30 ≥ ban threshold
        app(WarningService::class)->issue($mod, $target, spamWarning());
    }

    expect($target->fresh()->status)->toBe('banned');
    expect(Ban::where('user_id', $target->id)->where('type', 'user')->exists())->toBeTrue();
});

it('restores posting when the member acknowledges their warning', function () {
    $target = Users::inGroups(['members', 'tl2'], ['trust_level' => 2]);
    $warning = app(WarningService::class)->issue(Users::inGroups(['moderators']), $target, spamWarning());
    expect($target->fresh()->status)->toBe('pending');

    $this->actingAs($target->fresh())->post(route('warnings.acknowledge', $warning))->assertRedirect();

    expect($target->fresh()->status)->toBe('active');
});

it('lets staff issue via the route but forbids a non-staff member', function () {
    $target = Users::inGroups(['members']);

    $this->actingAs(Users::inGroups(['members']))
        ->post(route('warnings.store', $target), ['warning_type_id' => spamWarning()->id])->assertForbidden();

    $this->actingAs(Users::inGroups(['moderators']))
        ->post(route('warnings.store', $target), ['warning_type_id' => spamWarning()->id, 'reason' => 'spam'])->assertRedirect();

    expect(Warning::where('user_id', $target->id)->count())->toBe(1);
});
