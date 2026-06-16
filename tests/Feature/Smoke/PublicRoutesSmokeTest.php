<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Models\Topic;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| Post-install public-route smoke — installed state + the demo community, every public route returns no
| 5xx, twice. Cheap, and it catches a whole class early: the SECOND pass is the cache-HIT pass for
| fragment-cached routes (/forums, /sitemap.xml), driven through a SERIALIZING store (the live-host store).
| That is exactly RH-9's failure mode — "works on a miss, 500s on the next hit" — so a regression that
| caches an object again trips this before a real host does.
*/

beforeEach(function () {
    // The live-host store, where a cached object would poison the hit (RH-9). The array store the suite
    // defaults to never serializes, so it cannot catch this class.
    config(['cache.default' => 'database']);

    $this->seed();                  // base posture: groups, permissions, trust gates
    $this->seed(DemoSeeder::class); // a believable community: categories → forums → topics → posts → users
});

it('serves every public route with no 5xx, on first render and on the cache hit', function () {
    $forum = Forum::query()->where('type', 'forum')->firstOrFail();
    $topic = Topic::query()->firstOrFail();
    $user = User::query()->firstOrFail();

    $routes = [
        '/',                          // RH-4.1b: the forum index IS the home (RH-9 fragment-cached tree)
        '/forums',                    // RH-4.1b: legacy path → 301 → /
        '/forums/'.$forum->id,
        '/topics/'.$topic->id,
        '/search?q=community',
        '/sitemap.xml',               // fragment-cached XML
        '/robots.txt',
        '/health',
        '/users/'.$user->id,
        '/login',
        '/register',
    ];

    // Two passes: the second exercises the cache-hit deserialization path for fragment-cached routes.
    foreach (range(1, 2) as $pass) {
        foreach ($routes as $route) {
            $status = $this->get($route)->status();
            $this->assertLessThan(500, $status, "GET {$route} returned {$status} on pass {$pass}");
        }
    }
});
