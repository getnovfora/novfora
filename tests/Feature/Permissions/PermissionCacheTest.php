<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Permissions\AclVersion;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Acl;

/*
| Caching & invalidation (security §1.3). The resolved-permission cache is keyed by the global ACL
| version counter + the user's group-set signature; ANY ACL change bumps the version, invalidating
| stale sets. Correctness NEVER depends on the cache — a dead cache degrades to direct resolution.
*/

uses(RefreshDatabase::class);

const PC = 'forum.post';

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
});

it('serves an identical check from cache without re-running ACL queries', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);
    $acl->grant('members', PC, $acl->forumScope, V::Allow);

    $resolver = app(PermissionResolver::class);
    $resolver->flushMemo();
    expect($resolver->can($u->fresh(), PC, $acl->forumScope))->toBeTrue(); // MISS → warms the cache

    $resolver->flushMemo();        // drop the per-request memo so only the cross-request cache can serve it
    DB::flushQueryLog();
    DB::enableQueryLog();
    expect($resolver->can($u->fresh(), PC, $acl->forumScope))->toBeTrue(); // HIT
    $touchedAcl = collect(DB::getQueryLog())
        ->filter(fn ($q) => str_contains($q['query'], 'acl_entries') || str_contains($q['query'], 'bans'))
        ->count();
    DB::disableQueryLog();

    expect($touchedAcl)->toBe(0); // the resolution itself was cached; no ACL/ban work re-ran
});

it('invalidates the cache when an ACL entry changes (event-driven version bump)', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);
    $entry = $acl->grant('members', PC, $acl->forumScope, V::Allow);

    $resolver = app(PermissionResolver::class);
    $resolver->flushMemo();
    expect($resolver->can($u->fresh(), PC, $acl->forumScope))->toBeTrue(); // cached true

    $entry->update(['value' => V::Never->value]); // model save → AclEntry::saved → version bump

    $resolver->flushMemo();
    expect($resolver->can($u->fresh(), PC, $acl->forumScope))->toBeFalse(); // recomputed, not stale
});

it('changes the cache key when group membership changes (signature-keyed)', function () {
    $acl = Acl::make();
    $u = $acl->user(['plain']);                            // not yet a member of the granted group
    $acl->grant('privileged', PC, $acl->forumScope, V::Allow);

    $resolver = app(PermissionResolver::class);
    $resolver->flushMemo();
    expect($resolver->can($u->fresh(), PC, $acl->forumScope))->toBeFalse(); // cached false for this group-set

    $u->groups()->attach($acl->group('privileged')->id, ['is_primary' => false]); // group-set changes

    $resolver->flushMemo();
    expect($resolver->can($u->fresh(), PC, $acl->forumScope))->toBeTrue(); // new signature → new key → fresh
});

it('resolves correctly even when the cache backend is dead', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);
    $acl->grant('members', PC, $acl->forumScope, V::Allow);
    $user = $u->fresh();

    // Break the cache entirely: reads and read-throughs throw; correctness must not depend on them.
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));
    Cache::shouldReceive('remember')->andThrow(new RuntimeException('cache down'));
    Cache::shouldReceive('forever')->andReturnTrue();

    $resolver = app(PermissionResolver::class);
    $resolver->flushMemo();

    expect($resolver->can($user, PC, $acl->forumScope))->toBeTrue();   // direct resolution
    expect($resolver->explain($user, PC, $acl->forumScope)->granted)->toBeTrue();
});

it('memoises within a request and clears on flush', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);
    $acl->grant('members', PC, $acl->forumScope, V::Allow);
    $user = $u->fresh();

    $resolver = app(PermissionResolver::class);
    $resolver->flushMemo();

    $first = $resolver->explain($user, PC, $acl->forumScope);
    $second = $resolver->explain($user, PC, $acl->forumScope);
    expect($second)->toBe($first); // same Decision instance — served from the per-request memo

    $resolver->flushMemo();
    $third = $resolver->explain($user, PC, $acl->forumScope);
    expect($third)->not->toBe($first); // recomputed after the memo was cleared
});

it('bumps the version monotonically on each ACL write', function () {
    $version = app(AclVersion::class);
    $acl = Acl::make();
    $u = $acl->user(['members']);

    $before = $version->current();
    $acl->grant('members', PC, $acl->forumScope, V::Allow);
    $acl->grant('members', PC, $acl->global, V::No);

    expect($version->current())->toBeGreaterThan($before);
});
