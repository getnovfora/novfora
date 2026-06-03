<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\TrustLevelManager;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Models\Warning;
use App\Permissions\PermissionResolver;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Users;

/*
| Trust-level auto promotion/demotion (ADR-0007 §2.3 / data-model §4): membership in the TL groups is
| managed by stats + infractions, and because trust levels ARE ACL groups, the gates follow automatically.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

function givePosts(User $user, int $n): void
{
    $forum = Forum::create(['slug' => 'f'.$user->getKey(), 'title' => 'F', 'type' => 'forum']);
    $topic = Topic::create(['slug' => 't'.$user->getKey(), 'title' => 'T', 'forum_id' => $forum->id, 'user_id' => $user->getKey()]);

    // Bulk insert (bypasses model events/casts) — the manager only counts rows, and this keeps the
    // 300-post tenure test fast.
    $now = now();
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = [
            'topic_id' => $topic->id, 'user_id' => $user->getKey(), 'body_format' => 'tiptap_json',
            'body_canonical' => json_encode(['type' => 'doc', 'content' => []]),
            'body_html_cache' => '', 'body_text' => '', 'approved_state' => 'approved',
            'position' => $i, 'created_at' => $now, 'updated_at' => $now,
        ];
    }
    Post::insert($rows);
}

it('promotes a clean TL0 member to TL1 once posts + tenure thresholds are met, and the gate follows', function () {
    $user = Users::inGroups(['members', 'tl0']);
    $user->forceFill(['created_at' => now()->subDays(3)])->save(); // tl1 min_days = 1
    givePosts($user, 6);                                            // tl1 min_posts = 5

    // Before: TL0 is hard-gated from links.
    expect(app(PermissionResolver::class)->can($user->fresh(), 'post.links', Scope::global()))->toBeFalse();

    expect(app(TrustLevelManager::class)->recompute($user))->toBe(1);

    $user->refresh();
    expect((int) $user->trust_level)->toBe(1);
    expect($user->groups()->where('slug', 'tl1')->exists())->toBeTrue();
    expect($user->groups()->where('slug', 'tl0')->exists())->toBeFalse();

    // After: the TL1 gate grants links — resolved purely through the engine.
    app(PermissionResolver::class)->flushMemo();
    expect(app(PermissionResolver::class)->can($user->fresh(), 'post.links', Scope::global()))->toBeTrue();
});

it('freezes promotion while a (sub-threshold) flag is live', function () {
    $user = Users::inGroups(['members', 'tl0']);
    $user->forceFill(['created_at' => now()->subDays(3)])->save();
    givePosts($user, 6);
    Warning::create(['user_id' => $user->getKey(), 'points' => 3, 'expires_at' => now()->addDays(30)]);

    expect(app(TrustLevelManager::class)->recompute($user))->toBe(0);
    expect((int) $user->fresh()->trust_level)->toBe(0);
});

it('demotes to TL0 when live infraction points cross the demotion threshold', function () {
    $user = Users::inGroups(['members', 'tl2'], ['trust_level' => 2]);
    Warning::create(['user_id' => $user->getKey(), 'points' => 12, 'expires_at' => now()->addDays(30)]); // ≥ 10

    expect(app(TrustLevelManager::class)->recompute($user))->toBe(0);
    $user->refresh();
    expect((int) $user->trust_level)->toBe(0);
    expect($user->groups()->where('slug', 'tl0')->exists())->toBeTrue();
    expect($user->groups()->where('slug', 'tl2')->exists())->toBeFalse();
});

it('never auto-promotes to TL4 (leader is manual), capping a qualifying user at TL3', function () {
    $user = Users::inGroups(['members', 'tl0']);
    $user->forceFill(['created_at' => now()->subDays(90)])->save();
    givePosts($user, 300);

    expect(app(TrustLevelManager::class)->recompute($user))->toBe(3);
});

it('runs the scheduled command idempotently', function () {
    $user = Users::inGroups(['members', 'tl0']);
    $user->forceFill(['created_at' => now()->subDays(3)])->save();
    givePosts($user, 6);

    $this->artisan('hearth:trust:recompute')->assertSuccessful();
    expect((int) $user->fresh()->trust_level)->toBe(1);

    // A second pass changes nothing.
    $this->artisan('hearth:trust:recompute')->assertSuccessful();
    expect((int) $user->fresh()->trust_level)->toBe(1);
});
