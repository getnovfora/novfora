<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\TrustLevelManager;
use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\TopicRead;
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

/** Record that the user has READ $n distinct topics (phase-1.5 F-D: the §2.3 engagement signal). */
function giveReads(User $user, int $n): void
{
    $forum = Forum::create(['slug' => 'rf'.$user->getKey(), 'title' => 'RF', 'type' => 'forum']);
    for ($i = 0; $i < $n; $i++) {
        $topic = Topic::create(['slug' => 'rt'.$user->getKey().'-'.$i, 'title' => 'RT', 'forum_id' => $forum->id, 'user_id' => $user->getKey()]);
        TopicRead::create(['user_id' => $user->getKey(), 'topic_id' => $topic->id, 'last_read_at' => now()]);
    }
}

/** A TipTap doc with a single hyperlink — the TL0 link spam vector. @return array<string,mixed> */
function feDocWithLink(): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'see '],
            ['type' => 'text', 'text' => 'my site', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://promo.example.com']]]],
        ]],
    ]];
}

it('promotes a clean TL0 member to TL1 once posts + tenure thresholds are met, and the gate follows', function () {
    $user = Users::inGroups(['members', 'tl0']);
    $user->forceFill(['created_at' => now()->subDays(3)])->save(); // tl1 min_days = 1
    givePosts($user, 6);                                            // tl1 min_posts = 5
    giveReads($user, 5);                                            // tl1 min_topics_read = 5 (F-D)

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
    giveReads($user, 5); // qualifies on posts/tenure/reads — so the ONLY thing freezing promotion is the flag
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
    giveReads($user, 5); // clears the TL0→TL1 reads gate; higher tiers ride posts/tenure

    expect(app(TrustLevelManager::class)->recompute($user))->toBe(3);
});

it('runs the scheduled command idempotently', function () {
    $user = Users::inGroups(['members', 'tl0']);
    $user->forceFill(['created_at' => now()->subDays(3)])->save();
    givePosts($user, 6);
    giveReads($user, 5);

    $this->artisan('hearth:trust:recompute')->assertSuccessful();
    expect((int) $user->fresh()->trust_level)->toBe(1);

    // A second pass changes nothing.
    $this->artisan('hearth:trust:recompute')->assertSuccessful();
    expect((int) $user->fresh()->trust_level)->toBe(1);
});

it('keeps a self-poster who has read nothing at TL0 — the link gate holds (F-D)', function () {
    $user = Users::inGroups(['members', 'tl0']);
    $user->forceFill(['created_at' => now()->subDays(3)])->save();
    givePosts($user, 12);   // plenty of self-posts in their own topic…
    // …but zero topics read → the §2.3 engagement signal is unmet.

    expect(app(TrustLevelManager::class)->recompute($user))->toBe(0);
    expect((int) $user->fresh()->trust_level)->toBe(0);

    app(PermissionResolver::class)->flushMemo();
    expect(app(PermissionResolver::class)->can($user->fresh(), 'post.links', Scope::global()))->toBeFalse();
});

it('re-suppresses links in a user\'s existing posts when they are demoted (F-E)', function () {
    $user = Users::inGroups(['members', 'tl1'], ['trust_level' => 1]);
    $forum = Forum::create(['slug' => 'fe', 'title' => 'FE', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($user, $forum, 'A link', 'tiptap_json', feDocWithLink());
    $post = $topic->posts()->firstOrFail();

    // TL1 → the link renders.
    expect($post->body_html_cache)->toContain('<a ')->toContain('promo.example.com');

    // Demote past the threshold → recompute moves the user to TL0 and dispatches the re-render (sync).
    Warning::create(['user_id' => $user->getKey(), 'points' => 12, 'expires_at' => now()->addDays(30)]);
    app(TrustLevelManager::class)->recompute($user);
    expect((int) $user->fresh()->trust_level)->toBe(0);

    // The previously-rendered link is now suppressed (text kept, anchor + URL gone).
    expect($post->fresh()->body_html_cache)
        ->toContain('my site')
        ->not->toContain('<a ')
        ->not->toContain('promo.example.com');
});
