<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Load-test fixture seeder (Wave 8.3). Verified at small scale: it creates the requested forums/topics/posts
| through the real write path, is additive/idempotent on re-run, and produces queryable content.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('seeds the requested forums, topics and posts through the real write path', function () {
    $this->artisan('novfora:loadtest:seed', [
        '--forums' => 2, '--topics' => 3, '--posts' => 2, '--users' => 4, '--force' => true,
    ])->assertSuccessful();

    // 2 forums under the Load Test category.
    expect(Forum::where('slug', 'like', 'loadtest-forum-%')->count())->toBe(2)
        ->and(Forum::where('slug', 'loadtest')->where('type', 'category')->exists())->toBeTrue()
        ->and(User::where('email', 'like', 'loadtest%@example.test')->count())->toBe(4);

    // 2 forums × 3 topics = 6 topics; each topic = 1 opening post + 2 replies = 3 → 18 posts.
    $forumIds = Forum::where('slug', 'like', 'loadtest-forum-%')->pluck('id');
    $topics = Topic::whereIn('forum_id', $forumIds)->get();
    expect($topics)->toHaveCount(6);

    $postCount = Post::whereIn('topic_id', $topics->pluck('id'))->count();
    expect($postCount)->toBe(18);
});

it('writes real, counter-correct content (not hand-built rows)', function () {
    $this->artisan('novfora:loadtest:seed', [
        '--forums' => 1, '--topics' => 2, '--posts' => 1, '--users' => 2, '--force' => true,
    ])->assertSuccessful();

    $topic = Topic::whereHas('forum', fn ($q) => $q->where('slug', 'loadtest-forum-0'))->firstOrFail();

    // The opening post + 1 reply, with the denormalised reply_count maintained by the real write path
    // (1 reply on top of the opening post) and rendered text the search projection can match.
    expect($topic->posts()->count())->toBe(2)
        ->and($topic->fresh()->reply_count)->toBe(1)
        ->and((string) $topic->posts()->first()->body_text)->not->toBe('');
});

it('is additive and idempotent on re-run (grows, does not duplicate)', function () {
    $args = ['--forums' => 1, '--topics' => 2, '--posts' => 1, '--users' => 2, '--force' => true];

    $this->artisan('novfora:loadtest:seed', $args)->assertSuccessful();
    $this->artisan('novfora:loadtest:seed', $args)->assertSuccessful(); // same args again

    // Re-running with identical counts must NOT create a second set of forums/users.
    expect(Forum::where('slug', 'like', 'loadtest-forum-%')->count())->toBe(1)
        ->and(User::where('email', 'like', 'loadtest%@example.test')->count())->toBe(2);
});
