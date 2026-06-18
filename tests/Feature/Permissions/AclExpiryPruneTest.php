<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Permissions\AclVersion;
use App\Permissions\PermissionInspector;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;

/*
| ACP v3 · v3-0 (ADR-0080 §5): the `novfora:acl:prune-expired` cron + the inspector's view of TTL grants.
| The prune is HYGIENE — the resolver filter is already authoritative — so the contract here is: it deletes
| exactly the lapsed rows (leaving NULL/never-expire and future rows untouched) and bumps AclVersion only when
| it actually removed something (so resolved-permission caches refresh). The inspector (G4 oracle) reports a
| live TTL grant as granted with its expiry visible, and a lapsed one as no longer granted.
*/

uses(RefreshDatabase::class);

const PP = 'forum.post';

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
});

describe('novfora:acl:prune-expired', function () {
    it('hard-deletes lapsed rows and leaves NULL + future rows untouched', function () {
        $acl = Acl::make();

        $never = $acl->grant('members', PP, $acl->forumScope, V::Allow);                  // NULL = never-expire
        $future = $acl->grant('members', PP, $acl->global, V::Allow, now()->addDay());     // live TTL
        $lapsed = $acl->grant('members', PP, $acl->catScope, V::Allow, now()->subMinute()); // lapsed TTL
        $boundary = $acl->grant('members', PP, $acl->subScope, V::Allow, now()->subSecond());

        $this->artisan('novfora:acl:prune-expired')
            ->expectsOutputToContain('Pruned 2 expired')
            ->assertSuccessful();

        expect(AclEntry::whereKey($never->id)->exists())->toBeTrue();
        expect(AclEntry::whereKey($future->id)->exists())->toBeTrue();
        expect(AclEntry::whereKey($lapsed->id)->exists())->toBeFalse();
        expect(AclEntry::whereKey($boundary->id)->exists())->toBeFalse();
    });

    it('bumps AclVersion when it deletes something (so caches refresh)', function () {
        $acl = Acl::make();
        $acl->grant('members', PP, $acl->forumScope, V::Allow, now()->subMinute());

        $before = app(AclVersion::class)->current();
        $this->artisan('novfora:acl:prune-expired')->assertSuccessful();
        $after = app(AclVersion::class)->current();

        expect($after)->toBeGreaterThan($before);
    });

    it('does NOT bump AclVersion when there is nothing to prune', function () {
        $acl = Acl::make();
        $acl->grant('members', PP, $acl->forumScope, V::Allow);                 // NULL
        $acl->grant('members', PP, $acl->global, V::Allow, now()->addDay());     // future

        $before = app(AclVersion::class)->current();
        $this->artisan('novfora:acl:prune-expired')
            ->expectsOutputToContain('Pruned 0 expired')
            ->assertSuccessful();
        $after = app(AclVersion::class)->current();

        expect($after)->toBe($before);
    });

    it('is a safe no-op on an empty table', function () {
        $this->artisan('novfora:acl:prune-expired')
            ->expectsOutputToContain('Pruned 0 expired')
            ->assertSuccessful();
    });
});

describe('the inspector reports TTL grants (G4 oracle)', function () {
    it('shows a live TTL grant as granted, with the expiry visible in the entries', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', PP, $acl->forumScope, V::Allow, now()->addHour());

        app(PermissionResolver::class)->flushMemo();
        $report = app(PermissionInspector::class)->inspect($u->fresh(), PP, $acl->forumScope);

        expect($report['granted'])->toBeTrue();
        expect($report['reason'])->toBe('group_allow');
        expect(collect($report['entries'])->firstWhere('value', 'Allow'))->toHaveKey('expires_at');
    });

    it('shows a lapsed TTL grant as no longer granted (the entry is absent from the trace)', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', PP, $acl->forumScope, V::Allow, now()->subHour());

        app(PermissionResolver::class)->flushMemo();
        $report = app(PermissionInspector::class)->inspect($u->fresh(), PP, $acl->forumScope);

        expect($report['granted'])->toBeFalse();
        expect($report['reason'])->toBe('default');
        expect($report['entries'])->toBe([]);
    });
});

// The cached can() (the public Gate API) must stay authoritative across a TTL boundary that is crossed by the
// mere passage of wall-clock time — i.e. with NO model write (so no AclVersion bump) and NO prune. The cache
// horizon is capped to the earliest contributing TTL, so the resolved verdict self-expires exactly when its
// grant does, independent of the prune cron (ADR-0080 §5 — the apex-review finding this block pins).
describe('the cached can() is authoritative across the expiry boundary (no write, no prune)', function () {
    it('never serves a lapsed TTL ALLOW from cache, even if the prune never runs', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', PP, $acl->forumScope, V::Allow, now()->addSeconds(60)); // live now, lapses in 60s

        $resolver = app(PermissionResolver::class);
        expect($resolver->can($u->fresh(), PP, $acl->forumScope))->toBeTrue(); // warm the cache while live

        $this->travel(61)->seconds();   // wall-clock past expiry — NOT a write, so AclVersion is unchanged
        $resolver->flushMemo();          // a fresh request starts with an empty per-request memo

        expect($resolver->can($u->fresh(), PP, $acl->forumScope))->toBeFalse(); // cache self-expired → recompute
    });

    it('flips a cached DENY to ALLOW the instant a contributing TTL NEVER lapses (no write, no prune)', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', PP, $acl->forumScope, V::Allow);                          // standing NULL allow
        $acl->grant('members', PP, $acl->forumScope, V::Never, now()->addSeconds(60));    // TTL hard-deny

        $resolver = app(PermissionResolver::class);
        expect($resolver->can($u->fresh(), PP, $acl->forumScope))->toBeFalse(); // NEVER wins; cached false, cap 60s

        $this->travel(61)->seconds();
        $resolver->flushMemo();

        expect($resolver->can($u->fresh(), PP, $acl->forumScope))->toBeTrue(); // NEVER lapsed → the ALLOW surfaces
    });
});
