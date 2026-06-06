<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Group;
use App\Permissions\PermissionValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
| RH-9 — the forum index must survive a cache HIT through a SERIALIZING store.
|
| config/cache.php hardens the cache with `serializable_classes => false` (anti object-injection). On a
| serializing store (database/file/redis — every real deployment) that turns any cached OBJECT into a
| `__PHP_Incomplete_Class` on read. ForumController@index used to cache a live Eloquent Collection, so the
| SECOND request — the cache hit — deserialized it to an incomplete class whose first property is a string,
| and forum/index.blade.php's `$node->isCategory()` then fatalled: "Call to a member function isCategory()
| on string" — the live /forums 500, alternating with the TTL.
|
| The whole suite runs CACHE_STORE=array with serialize=false, so objects round-trip by reference and the
| hardening never applies — which is precisely why every prior test missed it. These pin the cache HIT
| through the DATABASE store (the live-host store): the cache table is migrated by RefreshDatabase and
| shares the sqlite :memory: connection, so the first request's write is read back by the second through
| serialize()/unserialize(allowed_classes:false).
*/

beforeEach(function () {
    config(['cache.default' => 'database']);
    $this->seed(); // default posture: guests/members can forum.view
    Cache::store('database')->forget('forum.index.tree');
});

it('renders the forum index on a cache hit through a serializing store (RH-9)', function () {
    $category = Forum::create(['slug' => 'main', 'title' => 'Main Category', 'type' => 'category']);
    Forum::create(['slug' => 'general', 'title' => 'General Chat', 'type' => 'forum', 'parent_id' => $category->id]);

    // First request: cache MISS — populates forum.index.tree in the database store.
    $this->get('/forums')->assertOk()->assertSee('Main Category')->assertSee('General Chat');

    // Second request: cache HIT — deserializes from the store. On buggy main this 500s with
    // "isCategory() on string"; with the primitive-array fix it renders identically.
    $this->get('/forums')->assertOk()->assertSee('Main Category')->assertSee('General Chat');
});

it('caches only a primitive array tree for the index — never a model or Collection (RH-9)', function () {
    $category = Forum::create(['slug' => 'main', 'title' => 'Main', 'type' => 'category']);
    Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum', 'parent_id' => $category->id]);

    $this->get('/forums')->assertOk();

    // What sits in the serializing store must be plain scalars + nested arrays, so a future
    // unserialize(allowed_classes:false) leaves it intact (an object would become __PHP_Incomplete_Class).
    $cached = Cache::store('database')->get('forum.index.tree');
    expect($cached)->toBeArray();

    $top = $cached[0];
    expect($top)->toBeArray()
        ->and($top['title'])->toBeString()
        ->and($top['type'])->toBeString()
        ->and($top['children'])->toBeArray()
        ->and($top['children'][0])->toBeArray()
        ->and($top['children'][0]['title'])->toBe('General');
});

it('still applies per-viewer forum.view filtering after the cache (cache is not load-bearing)', function () {
    // The cached tree is viewer-independent; visibility is decided per request, after rehydration. Prove a
    // NEVER on forum.view hides the row even though the tree itself is cached.
    $forum = Forum::create(['slug' => 'secret', 'title' => 'Secret Forum', 'type' => 'forum']);

    AclEntry::create([
        'permission_key' => 'forum.view',
        'holder_type' => 'group',
        'holder_id' => Group::where('slug', 'guests')->value('id'),
        'scope_type' => 'forum',
        'scope_id' => $forum->id,
        'value' => PermissionValue::Never->value,
    ]);

    // Warm the cache, then a second (hit) request — a guest must never see the NEVER'd forum either time.
    $this->get('/forums')->assertOk()->assertDontSee('Secret Forum');
    $this->get('/forums')->assertOk()->assertDontSee('Secret Forum');
});
