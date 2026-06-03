<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;

/*
| The non-negotiable permission-mask truth table (ADR-0006 / security §1.2). Exhaustive across:
|   value semantics (ALLOW / NO / NEVER) × scope chain (global → category → forum → thread)
|   × group merge × primary-vs-secondary × bans × the §1.5 edge cases.
|
| Every assertion runs through Acl::assertDecision(), which uses the inspector trace explain() as
| the ORACLE and verifies the cached boolean can() agrees with it (DoD requirement).
|
| NO is NEUTRAL/inherit ("interpretation ii", security §1.1 + §2.3) — NOT a soft-deny. The cases in
| the "NO is neutral" block pin that decision; flipping the single marked branch in
| PermissionResolver::compute() to strict-"i" would invert exactly those.  [FLAGGED for sign-off.]
*/

uses(RefreshDatabase::class);

const P = 'forum.post'; // the permission under test

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
});

// ── 1. value semantics at the queried scope ────────────────────────────────────────────────────
describe('value at the queried scope', function () {
    it('grants on a group ALLOW', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->forumScope, V::Allow);
        $acl->assertDecision($u, P, $acl->forumScope, true, 'group_allow');
    });

    it('denies on a group NO (neutral → nothing grants) → default', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->forumScope, V::No);
        $acl->assertDecision($u, P, $acl->forumScope, false, 'default');
    });

    it('denies on a group NEVER', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->forumScope, V::Never);
        $acl->assertDecision($u, P, $acl->forumScope, false, 'never');
    });

    it('grants on a user ALLOW', function () {
        $acl = Acl::make();
        $u = $acl->user();
        $acl->grant($u, P, $acl->forumScope, V::Allow);
        $acl->assertDecision($u, P, $acl->forumScope, true, 'user_allow');
    });

    it('denies on a user NO (neutral) → default', function () {
        $acl = Acl::make();
        $u = $acl->user();
        $acl->grant($u, P, $acl->forumScope, V::No);
        $acl->assertDecision($u, P, $acl->forumScope, false, 'default');
    });

    it('denies on a user NEVER', function () {
        $acl = Acl::make();
        $u = $acl->user();
        $acl->grant($u, P, $acl->forumScope, V::Never);
        $acl->assertDecision($u, P, $acl->forumScope, false, 'never');
    });

    it('denies with no entries at all → default (deny-by-default)', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->assertDecision($u, P, $acl->forumScope, false, 'default');
    });
});

// ── 2. inheritance down the scope chain ────────────────────────────────────────────────────────
describe('inheritance (entry at an ancestor, query a descendant)', function () {
    it('inherits a global ALLOW to a thread', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->global, V::Allow);
        $d = $acl->assertDecision($u, P, $acl->threadScope, true, 'group_allow');
        expect($d->decidedAtScope?->key())->toBe('global:*');
    });

    it('inherits a category ALLOW to a thread', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->catScope, V::Allow);
        $d = $acl->assertDecision($u, P, $acl->threadScope, true, 'group_allow');
        expect($d->decidedAtScope?->key())->toBe('category:'.$acl->category->id);
    });

    it('inherits a forum ALLOW to a thread', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->forumScope, V::Allow);
        $acl->assertDecision($u, P, $acl->threadScope, true, 'group_allow');
    });

    it('inherits a forum ALLOW down to a subforum (depth 2)', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->forumScope, V::Allow);
        $acl->assertDecision($u, P, $acl->subScope, true, 'group_allow');
    });

    it('inherits a global NEVER to a thread (absolute)', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->global, V::Never);
        $acl->assertDecision($u, P, $acl->threadScope, false, 'never');
    });
});

// ── 3. specificity & scope isolation ───────────────────────────────────────────────────────────
describe('specificity & scope isolation', function () {
    it('does not leak a more-specific grant UP the chain', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->subScope, V::Allow); // granted at the subforum
        $acl->assertDecision($u, P, $acl->forumScope, false, 'default'); // parent sees nothing
    });

    it('isolates sibling threads (grant on one thread does not reach another)', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->threadScope, V::Allow);
        $acl->assertDecision($u, P, $acl->threadScope, true, 'group_allow');
        $acl->assertDecision($u, P, $acl->threadInSubScope, false, 'default');
    });

    it('applies a NEVER only within its own scope subtree', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->forumScope, V::Allow);  // broad allow
        $acl->grant('members', P, $acl->threadScope, V::Never); // hard-deny one thread
        $acl->assertDecision($u, P, $acl->threadScope, false, 'never'); // the cursed thread
        $acl->assertDecision($u, P, $acl->forumScope, true, 'group_allow'); // siblings unaffected
        $acl->assertDecision($u, P, $acl->threadInSubScope, true, 'group_allow');
    });
});

// ── 4. NO is neutral, not a soft-deny (interpretation "ii") ─────────────────────────────────────
describe('NO is neutral (interpretation ii) [FLAGGED]', function () {
    it('a more-specific group NO does NOT block an inherited group ALLOW', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->global, V::Allow);
        $acl->grant('members', P, $acl->forumScope, V::No); // would hard-deny under strict-(i)
        $d = $acl->assertDecision($u, P, $acl->forumScope, true, 'group_allow');
        expect($d->decidedAtScope?->key())->toBe('global:*'); // inherited past the NO
    });

    it('a user NO does NOT block a group ALLOW at the same scope', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant($u, P, $acl->forumScope, V::No);          // user neutral
        $acl->grant('members', P, $acl->forumScope, V::Allow); // group grants
        $acl->assertDecision($u, P, $acl->forumScope, true, 'group_allow');
    });

    it('a user NO does NOT block a group ALLOW inherited from a parent', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant($u, P, $acl->forumScope, V::No);
        $acl->grant('members', P, $acl->global, V::Allow);
        $acl->assertDecision($u, P, $acl->forumScope, true, 'group_allow');
    });

    it('NO at every scope with no ALLOW anywhere → default deny', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->global, V::No);
        $acl->grant('members', P, $acl->forumScope, V::No);
        $acl->assertDecision($u, P, $acl->forumScope, false, 'default');
    });
});

// ── 5. user vs group precedence ────────────────────────────────────────────────────────────────
describe('user vs group precedence', function () {
    it('a group NEVER overrides a user ALLOW at the same scope', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant($u, P, $acl->forumScope, V::Allow);
        $acl->grant('members', P, $acl->forumScope, V::Never);
        $acl->assertDecision($u, P, $acl->forumScope, false, 'never');
    });

    it('a user NEVER overrides a group ALLOW at the same scope', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant($u, P, $acl->forumScope, V::Never);
        $acl->grant('members', P, $acl->forumScope, V::Allow);
        $acl->assertDecision($u, P, $acl->forumScope, false, 'never');
    });

    it('the most-specific grant decides when several would allow (user@forum wins)', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->global, V::Allow);
        $acl->grant($u, P, $acl->forumScope, V::Allow);
        $d = $acl->assertDecision($u, P, $acl->forumScope, true, 'user_allow');
        expect($d->decidedAtScope?->key())->toBe('forum:'.$acl->forum->id);
    });
});

// ── 6. group merge (most-permissive wins; NEVER is absolute) ────────────────────────────────────
describe('group merge', function () {
    it('ALLOW + NO across two groups → ALLOW (most-permissive)', function () {
        $acl = Acl::make();
        $u = $acl->user(['gyes', 'gno']);
        $acl->grant('gyes', P, $acl->forumScope, V::Allow);
        $acl->grant('gno', P, $acl->forumScope, V::No);
        $acl->assertDecision($u, P, $acl->forumScope, true, 'group_allow');
    });

    it('ALLOW + NEVER across two groups → DENY (NEVER is absolute) [§1.5]', function () {
        $acl = Acl::make();
        $u = $acl->user(['gyes', 'gnever']);
        $acl->grant('gyes', P, $acl->forumScope, V::Allow);
        $acl->grant('gnever', P, $acl->forumScope, V::Never);
        $acl->assertDecision($u, P, $acl->forumScope, false, 'never');
    });

    it('NO + NO across two groups → default deny', function () {
        $acl = Acl::make();
        $u = $acl->user(['gno1', 'gno2']);
        $acl->grant('gno1', P, $acl->forumScope, V::No);
        $acl->grant('gno2', P, $acl->forumScope, V::No);
        $acl->assertDecision($u, P, $acl->forumScope, false, 'default');
    });

    it('a NEVER in one group bites only within its scope; siblings still inherit the other group ALLOW', function () {
        $acl = Acl::make();
        $u = $acl->user(['gyes', 'gnever']);
        $acl->grant('gyes', P, $acl->global, V::Allow);
        $acl->grant('gnever', P, $acl->threadScope, V::Never);
        $acl->assertDecision($u, P, $acl->threadScope, false, 'never');     // the never-scoped thread
        $acl->assertDecision($u, P, $acl->forumScope, true, 'group_allow'); // elsewhere: inherited allow
    });
});

// ── 7. primary vs secondary group is irrelevant (most-permissive + NEVER, NOT group order) [§1.5] ─
describe('primary vs secondary is irrelevant', function () {
    it('ALLOW + NEVER → DENY regardless of which group is primary', function (string $primary) {
        $acl = Acl::make();
        $acl->grant('gyes', P, $acl->forumScope, V::Allow);
        $acl->grant('gnever', P, $acl->forumScope, V::Never);
        $u = $acl->user(['gyes', 'gnever'], primary: $primary);
        $acl->assertDecision($u, P, $acl->forumScope, false, 'never');
    })->with(['gyes', 'gnever']);

    it('ALLOW + NO → ALLOW regardless of which group is primary', function (string $primary) {
        $acl = Acl::make();
        $acl->grant('gyes', P, $acl->forumScope, V::Allow);
        $acl->grant('gno', P, $acl->forumScope, V::No);
        $u = $acl->user(['gyes', 'gno'], primary: $primary);
        $acl->assertDecision($u, P, $acl->forumScope, true, 'group_allow');
    })->with(['gyes', 'gno']);
});

// ── 8. bans are evaluated BEFORE ACL (security §1.2 step 1) ─────────────────────────────────────
describe('bans short-circuit before ACL', function () {
    it('a banned account status denies everywhere despite an ALLOW', function () {
        $acl = Acl::make();
        $u = $acl->user(['members'], ['status' => 'banned']);
        $acl->grant('members', P, $acl->forumScope, V::Allow);
        $acl->assertDecision($u, P, $acl->forumScope, false, 'banned');
    });

    it('a global ban row denies at every scope despite a user ALLOW', function () {
        $acl = Acl::make();
        $u = $acl->user();
        $acl->grant($u, P, $acl->global, V::Allow);
        $acl->ban($u, $acl->global);
        $acl->assertDecision($u, P, $acl->global, false, 'banned');
        $acl->assertDecision($u, P, $acl->threadScope, false, 'banned');
    });

    it('a forum-scoped ban covers its subtree but not its ancestors', function () {
        $acl = Acl::make();
        $u = $acl->user();
        $acl->grant($u, P, $acl->global, V::Allow);
        $acl->ban($u, $acl->forumScope);
        $acl->assertDecision($u, P, $acl->forumScope, false, 'banned');         // the banned forum
        $acl->assertDecision($u, P, $acl->subScope, false, 'banned');           // descendant forum
        $acl->assertDecision($u, P, $acl->threadScope, false, 'banned');        // descendant thread
        $acl->assertDecision($u, P, $acl->catScope, true, 'user_allow');        // ancestor: not banned
    });

    it('an expired ban is ignored', function () {
        $acl = Acl::make();
        $u = $acl->user();
        $acl->grant($u, P, $acl->forumScope, V::Allow);
        $acl->ban($u, $acl->forumScope, now()->subDay());
        $acl->assertDecision($u, P, $acl->forumScope, true, 'user_allow');
    });

    it('an active (future-dated) ban applies', function () {
        $acl = Acl::make();
        $u = $acl->user();
        $acl->grant($u, P, $acl->forumScope, V::Allow);
        $acl->ban($u, $acl->forumScope, now()->addDay());
        $acl->assertDecision($u, P, $acl->forumScope, false, 'banned');
    });
});

// ── 9. §1.5 edge cases: deleted / moved scopes inherit from the surviving parent ────────────────
describe('§1.5 deleted / moved scopes', function () {
    it('a deleted scope drops its own grants; the descendant inherits from the surviving parent', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->catScope, V::Allow);
        $acl->assertDecision($u, P, $acl->forumScope, true, 'group_allow'); // inherited from category

        $acl->category->delete(); // the granting scope disappears (no FK cascade to child forums)

        $acl->assertDecision($u, P, $acl->forumScope, false, 'default'); // grant gone → deny
    });

    it('a deleted mid-scope still lets the descendant inherit a surviving global grant', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->global, V::Allow);
        $acl->assertDecision($u, P, $acl->forumScope, true, 'group_allow');

        $acl->category->delete(); // remove the mid node

        $d = $acl->assertDecision($u, P, $acl->forumScope, true, 'group_allow');
        expect($d->decidedAtScope?->key())->toBe('global:*'); // inherits from the surviving root
    });

    it('moving a scope re-parents its inheritance', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', P, $acl->catScope, V::Allow);
        $acl->assertDecision($u, P, $acl->forumScope, true, 'group_allow');

        $other = Forum::create(['slug' => 'cat2', 'title' => 'Other category', 'type' => 'category']);
        $acl->forum->update([
            'parent_id' => $other->id,
            'path' => '/'.$other->id.'/'.$acl->forum->id.'/',
        ]);

        $acl->assertDecision($u, P, $acl->forumScope, false, 'default'); // old parent's grant no longer applies
    });
});

// ── 10. permission isolation ───────────────────────────────────────────────────────────────────
describe('permission isolation', function () {
    it('a grant for one permission never satisfies another', function () {
        $acl = Acl::make();
        $u = $acl->user(['members']);
        $acl->grant('members', 'forum.view', $acl->forumScope, V::Allow);
        $acl->assertDecision($u, 'forum.view', $acl->forumScope, true, 'group_allow');
        $acl->assertDecision($u, 'forum.post', $acl->forumScope, false, 'default');
    });
});
