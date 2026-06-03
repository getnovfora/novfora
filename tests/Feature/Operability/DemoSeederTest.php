<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| The demo seed (M5): a believable, idempotent starter community produced through the real PostService.
*/

it('seeds a believable community across trust levels, all approved + visible', function () {
    $this->seed(DatabaseSeeder::class);   // groups/permissions the demo authors need
    $this->seed(DemoSeeder::class);

    expect(Forum::where('type', 'category')->count())->toBeGreaterThanOrEqual(3);
    expect(Forum::where('type', 'forum')->count())->toBeGreaterThanOrEqual(5);

    $topics = Topic::count();
    $posts = Post::count();
    expect($topics)->toBeGreaterThan(0);
    expect($posts)->toBeGreaterThan($topics);                 // replies exist, not just opening posts

    // Authored by trusted users → everything is approved and therefore publicly visible.
    expect(Post::where('approved_state', 'approved')->count())->toBe($posts);

    // Users span the trust levels (TL0 lurker … TL4 leader).
    expect(User::where('trust_level', 0)->exists())->toBeTrue();
    expect(User::where('trust_level', 4)->exists())->toBeTrue();

    // Real write path → denormalised counters are maintained.
    expect(Forum::where('slug', 'announcements')->first()->topic_count)->toBeGreaterThan(0);
});

it('is idempotent — re-running adds nothing', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoSeeder::class);

    $forums = Forum::count();
    $topics = Topic::count();
    $posts = Post::count();
    $users = User::count();

    $this->seed(DemoSeeder::class);   // second run

    expect(Forum::count())->toBe($forums);
    expect(Topic::count())->toBe($topics);
    expect(Post::count())->toBe($posts);
    expect(User::count())->toBe($users);
});
