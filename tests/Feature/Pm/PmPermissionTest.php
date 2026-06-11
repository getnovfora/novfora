<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Group;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;

/*
| PM gating runs ENTIRELY through the M1 permission engine — pm.send is seeded NEVER on the TL0 group and
| ALLOW from TL1 up (config antispam.trust_gates). PMs are a new mass-spam surface, so the TL0 mass-PM NEVER
| must be ABSOLUTE: no per-user, per-scope, or group ALLOW may lift it (security §1.2 step 5). This mirrors
| the post.links / tag.create anti-spam pins. pm.send is a GLOBAL-scope key, so the decisive scope is global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

it('hard-gates a TL0 account from sending PMs — NEVER, through the engine', function () {
    $acl = Acl::make();
    $newbie = $acl->user(['members', 'tl0']);

    $acl->assertDecision($newbie, 'pm.send', $acl->global, false, 'never');
});

it('lets a TL1 account send PMs', function () {
    $acl = Acl::make();
    $trusted = $acl->user(['members', 'tl1']);

    $acl->assertDecision($trusted, 'pm.send', $acl->global, true);
});

it('an admin per-user ALLOW cannot lift the TL0 mass-PM NEVER (the lockdown is absolute)', function () {
    $acl = Acl::make();
    $newbie = $acl->user(['members', 'tl0']);

    // An admin explicitly grants THIS user pm.send at global AND at more-specific scopes...
    $acl->grant($newbie, 'pm.send', $acl->global, V::Allow);
    $acl->grant($newbie, 'pm.send', $acl->forumScope, V::Allow);
    $acl->grant($newbie, 'pm.send', $acl->threadScope, V::Allow);

    // ...yet the TL0 group's global NEVER still wins. This is exactly what NEVER's absoluteness is for.
    $acl->assertDecision($newbie, 'pm.send', $acl->global, false, 'never');
    $acl->assertDecision($newbie, 'pm.send', $acl->threadScope, false, 'never');
});

it('a group ALLOW cannot lift the TL0 mass-PM NEVER either', function () {
    $acl = Acl::make();
    // A TL0 user who is ALSO in a custom group that an admin grants pm.send=ALLOW at global...
    $newbie = $acl->user(['members', 'tl0', 'vip']);
    $acl->grant('vip', 'pm.send', $acl->global, V::Allow);

    // ...is still blocked: NEVER on the TL0 group beats the most-permissive group ALLOW.
    $acl->assertDecision($newbie, 'pm.send', $acl->global, false, 'never');
});

it('explains a TL0 PM block as the trust group NEVER (the inspector oracle)', function () {
    $acl = Acl::make();
    $newbie = $acl->user(['members', 'tl0']);
    $tl0 = Group::where('slug', 'tl0')->firstOrFail();

    $decision = $acl->explain($newbie, 'pm.send', $acl->global);

    expect($decision->granted)->toBeFalse()
        ->and($decision->reason)->toBe('never')
        ->and($decision->decidedByHolder)->toBe('group#'.$tl0->id);
});

it('never spam-gates staff: a moderator may send PMs', function () {
    $acl = Acl::make();
    $mod = $acl->user(['moderators']);

    $acl->assertDecision($mod, 'pm.send', $acl->global, true);
});
