<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\BadgeService;
use App\Forum\PostService;
use App\Forum\ReactionService;
use App\Models\Badge;
use App\Models\Forum;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

/*
| The badge event wiring (P2-M5): registration → join badge; PostCreated → post-count criteria; a real
| reputation award (Slice-2 reaction wiring) crossing a threshold → rep badge. All queued listeners run
| inline here (QUEUE_CONNECTION=sync), post-commit.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(); // includes the starter badge set (welcome / first-post / conversationalist / well-regarded)
    config(['novfora.reactions.types' => [
        'helpful' => ['label' => 'Helpful', 'emoji' => '💡', 'score' => 10],
    ]]);
});

function badgeSlugsFor(User $user): array
{
    return DB::table('user_badges')
        ->join('badges', 'badges.id', '=', 'user_badges.badge_id')
        ->where('user_badges.user_id', $user->getKey())
        ->orderBy('badges.slug')
        ->pluck('badges.slug')
        ->all();
}

it('awards the welcome badge on registration', function () {
    $user = Users::inGroups(['members', 'tl0']);

    event(new Registered($user));

    expect(badgeSlugsFor($user))->toContain('welcome');
});

it('awards the first-post badge when the first post lands', function () {
    $author = Users::inGroups(['members', 'tl1']);
    $forum = Forum::create(['slug' => 'badge-wire', 'title' => 'Badge wire', 'type' => 'forum']);

    app(PostService::class)->createTopic($author, $forum, 'Hello world', 'markdown', ['source' => 'first!']);

    expect(badgeSlugsFor($author))->toContain('first-post');
});

it('awards the rep-threshold badge when a Slice-2 reaction award crosses the threshold', function () {
    $author = Users::inGroups(['members', 'tl1']);
    $reactor = Users::inGroups(['members', 'tl1']);
    $forum = Forum::create(['slug' => 'badge-rep', 'title' => 'Badge rep', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Niche wisdom', 'markdown', ['source' => 'wise']);

    expect(badgeSlugsFor($author))->not->toContain('well-regarded'); // 0 rep — under the threshold of 10

    app(ReactionService::class)->toggle($reactor, $topic->posts()->first(), 'helpful'); // +10 → crosses

    expect($author->fresh()->reputation_points)->toBe(10)
        ->and(badgeSlugsFor($author))->toContain('well-regarded');
});

it('shows earned badges on the public profile', function () {
    $user = Users::inGroups(['members', 'tl1']);
    $badge = Badge::where('slug', 'welcome')->firstOrFail();
    app(BadgeService::class)->award($user, $badge);

    $this->get(route('profiles.show', $user))
        ->assertOk()
        ->assertSeeHtml('dusk="profile-badge-welcome"')
        ->assertSee('Welcome');
});
