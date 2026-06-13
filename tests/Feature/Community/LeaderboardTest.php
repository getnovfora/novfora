<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\ReputationEvent;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| The public "Top members" leaderboard (/members/top, <livewire:leaderboard>). It shares the members-directory
| visibility gate, ranks ACTIVE members by reputation or posts, and supports an all-time / 30-day / 7-day
| window. All-time reads the denormalised columns; windowed views aggregate the source tables (reputation
| ledger, approved non-deleted posts). Ties break by ascending user id. Pins: gating, ordering, ties, windows.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function setLbVisibility(string $mode): void
{
    app(Settings::class)->set('members.directory_visibility', $mode);
}

it('404s the leaderboard for everyone (incl. staff) when the directory is disabled', function () {
    setLbVisibility('disabled');
    $this->get(route('members.top'))->assertNotFound();
    $this->actingAs(Users::inGroups(['moderators']))->get(route('members.top'))->assertNotFound();
});

it('shows the leaderboard to guests when visibility is everyone', function () {
    setLbVisibility('everyone');
    $this->get(route('members.top'))->assertOk();
});

it('hides the leaderboard from guests but shows members when members-only', function () {
    setLbVisibility('members');
    $this->get(route('members.top'))->assertNotFound();
    $this->actingAs(Users::inGroups(['members', 'tl0']))->get(route('members.top'))->assertOk();
});

it('restricts the leaderboard to staff when staff-only', function () {
    setLbVisibility('staff');
    $this->actingAs(Users::inGroups(['members', 'tl0']))->get(route('members.top'))->assertNotFound();
    $this->actingAs(Users::inGroups(['moderators']))->get(route('members.top'))->assertOk();
});

it('ranks members by all-time reputation, excludes zero, and breaks ties by id', function () {
    setLbVisibility('everyone');
    $top = Users::inGroups(['members'], ['username' => 'topreputation']);
    $top->forceFill(['reputation_points' => 100])->saveQuietly();
    $mid = Users::inGroups(['members'], ['username' => 'midreputation']);
    $mid->forceFill(['reputation_points' => 50])->saveQuietly();
    $tie = Users::inGroups(['members'], ['username' => 'tiereputation']); // same 50, created later → higher id
    $tie->forceFill(['reputation_points' => 50])->saveQuietly();
    Users::inGroups(['members'], ['username' => 'zeroreputation']); // 0 rep → not ranked

    $this->get(route('members.top'))
        ->assertOk()
        ->assertSeeInOrder(['topreputation', 'midreputation', 'tiereputation']) // desc, tie by id asc
        ->assertDontSee('zeroreputation');
});

it('ranks by all-time post count under the posts metric', function () {
    setLbVisibility('everyone');
    $a = Users::inGroups(['members'], ['username' => 'heavyallposts']);
    $a->forceFill(['post_count' => 30])->saveQuietly();
    $b = Users::inGroups(['members'], ['username' => 'lightallposts']);
    $b->forceFill(['post_count' => 10])->saveQuietly();
    Users::inGroups(['members'], ['username' => 'zeroallposts']); // 0 posts → not ranked

    Livewire::test('leaderboard')
        ->set('metric', 'posts')
        ->assertSeeInOrder(['heavyallposts', 'lightallposts'])
        ->assertDontSee('zeroallposts');
});

it('windows reputation to the selected timeframe from the ledger', function () {
    setLbVisibility('everyone');
    $recent = Users::inGroups(['members'], ['username' => 'recentwindowrep']);
    $old = Users::inGroups(['members'], ['username' => 'oldwindowrep']);

    ReputationEvent::create(['user_id' => $recent->id, 'source_type' => 'test', 'source_id' => 1, 'points' => 20, 'created_at' => now()->subDays(2)]);
    ReputationEvent::create(['user_id' => $old->id, 'source_type' => 'test', 'source_id' => 2, 'points' => 500, 'created_at' => now()->subDays(60)]);

    // 7-day window: only the recent event counts; the older (much larger) total falls outside it.
    Livewire::test('leaderboard')
        ->set('timeframe', 'week')
        ->assertSee('recentwindowrep')
        ->assertDontSee('oldwindowrep');

    // 30-day window still excludes the 60-day-old event.
    Livewire::test('leaderboard')
        ->set('timeframe', 'month')
        ->assertSee('recentwindowrep')
        ->assertDontSee('oldwindowrep');
});

it('windows posts to the timeframe, counting only approved non-deleted posts', function () {
    setLbVisibility('everyone');
    $forum = Forum::create(['slug' => 'lb', 'title' => 'LB', 'type' => 'forum']);
    $seeder = Users::inGroups(['members']);
    $topic = app(PostService::class)->createTopic($seeder, $forum, 'Seed topic', 'markdown', ['source' => 'b']);

    $heavy = Users::inGroups(['members'], ['username' => 'heavywindowposter']);
    $light = Users::inGroups(['members'], ['username' => 'lightwindowposter']);

    $row = fn (int $uid, string $state, $when, bool $deleted = false): array => [
        'topic_id' => $topic->id, 'user_id' => $uid, 'body_format' => 'markdown', 'body_canonical' => 'x',
        'approved_state' => $state, 'created_at' => $when, 'updated_at' => $when,
        'deleted_at' => $deleted ? now() : null,
    ];

    DB::table('posts')->insert([
        $row($heavy->id, 'approved', now()->subDay()),
        $row($heavy->id, 'approved', now()->subDay()),
        $row($heavy->id, 'approved', now()->subDay()),
        $row($heavy->id, 'pending', now()->subDay()),         // pending → excluded
        $row($heavy->id, 'approved', now()->subDay(), true),  // soft-deleted → excluded
        $row($heavy->id, 'approved', now()->subDays(60)),     // outside the 30-day window → excluded
        $row($light->id, 'approved', now()->subDay()),
    ]);

    // heavy has 3 in-window approved posts, light has 1 → heavy ranks first.
    Livewire::test('leaderboard')
        ->set('metric', 'posts')
        ->set('timeframe', 'month')
        ->assertSeeInOrder(['heavywindowposter', 'lightwindowposter']);
});
