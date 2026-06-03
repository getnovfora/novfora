<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Models\Group;
use App\Permissions\PermissionInspector;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;

/*
| The crux of M3 (ADR-0007 §2.3): trust-level anti-spam gating runs ENTIRELY through the M1 permission
| engine — no second permission system. The seeded TL0 group carries NEVER on true spam vectors, so the
| lockdown is absolute (no admin ALLOW can lift it), and the inspector explains the block precisely.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

it('hard-gates a TL0 account from links/images — NEVER, through the engine', function () {
    $acl = Acl::make();
    $newbie = $acl->user(['members', 'tl0']);

    $acl->assertDecision($newbie, 'post.links', $acl->forumScope, false, 'never');
    $acl->assertDecision($newbie, 'post.images', $acl->threadScope, false, 'never');
});

it('lets a TL1 account post links and images', function () {
    $acl = Acl::make();
    $trusted = $acl->user(['members', 'tl1']);

    $acl->assertDecision($trusted, 'post.links', $acl->forumScope, true);
    $acl->assertDecision($trusted, 'post.images', $acl->threadScope, true);
});

it('an admin ALLOW cannot lift the TL0 NEVER (the spam-vector lockdown is absolute)', function () {
    $acl = Acl::make();
    $newbie = $acl->user(['members', 'tl0']);

    // An admin explicitly grants THIS user post.links at the forum and the thread...
    $acl->grant($newbie, 'post.links', $acl->forumScope, V::Allow);
    $acl->grant($newbie, 'post.links', $acl->threadScope, V::Allow);

    // ...yet the TL0 group's global NEVER still wins. This is exactly what NEVER's absoluteness is for.
    $acl->assertDecision($newbie, 'post.links', $acl->threadScope, false, 'never');
});

it('explains a TL0 block as the trust group NEVER (the inspector)', function () {
    $acl = Acl::make();
    $newbie = $acl->user(['members', 'tl0']);
    $tl0 = Group::where('slug', 'tl0')->firstOrFail();

    $report = app(PermissionInspector::class)->inspect($newbie->fresh(), 'post.links', $acl->forumScope);

    expect($report['granted'])->toBeFalse();
    expect($report['reason'])->toBe('never');
    expect($report['decided_by'])->toBe('group#'.$tl0->id);
    expect(collect($report['entries'])->contains(
        fn (array $e) => $e['holder'] === 'group#'.$tl0->id && $e['value'] === 'Never',
    ))->toBeTrue();
});

it('treats attachments as a soft seam: members grants it, the TL0 NO does not block', function () {
    $acl = Acl::make();
    $newbie = $acl->user(['members', 'tl0']);

    // members ALLOWs attachment.create at global; tl0 carries NO (neutral) → most-permissive wins → ALLOW.
    // (A soft gate is admin-liftable by design; the hard gates above are not.)
    $acl->assertDecision($newbie, 'attachment.create', $acl->forumScope, true);
});

it('never spam-gates staff: a moderator may post links', function () {
    $acl = Acl::make();
    $mod = $acl->user(['moderators']);

    $acl->assertDecision($mod, 'post.links', $acl->forumScope, true);
});

it('seeds the trust gates as acl_entries on the TL groups (configurable defaults)', function () {
    $tl0 = Group::where('slug', 'tl0')->firstOrFail();

    $entry = AclEntry::where('holder_type', 'group')->where('holder_id', $tl0->id)
        ->where('permission_key', 'post.links')->where('scope_type', 'global')->firstOrFail();

    expect((int) $entry->value)->toBe(V::Never->value);
});
