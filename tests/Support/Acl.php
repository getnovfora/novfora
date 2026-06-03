<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AclEntry;
use App\Models\Ban;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\Decision;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue;
use App\Permissions\Scope;

/**
 * Test fixture builder for the permission-mask engine (ADR-0006 / security §1).
 *
 * Provides a canonical four-level scope tree
 *     global → category → forum → subforum, with a thread in the forum and one in the subforum
 * plus terse helpers to attach groups, write three-state ACL entries, and issue bans, then assert a
 * decision with the inspector trace as the oracle: every assertion checks that the cached boolean
 * `can()` agrees with the full `explain()` resolution, and (optionally) the decisive reason.
 */
final class Acl
{
    public Forum $category;

    public Forum $forum;

    public Forum $subforum;

    public Topic $thread;        // lives in $forum

    public Topic $threadInSub;   // lives in $subforum

    public Scope $global;

    public Scope $catScope;

    public Scope $forumScope;

    public Scope $subScope;

    public Scope $threadScope;

    public Scope $threadInSubScope;

    /** @var array<string,Group> */
    private array $groups = [];

    public function __construct()
    {
        $this->category = Forum::create(['slug' => 'cat', 'title' => 'Category', 'type' => 'category']);
        $this->forum = Forum::create(['slug' => 'forum', 'title' => 'Forum', 'type' => 'forum', 'parent_id' => $this->category->id]);
        $this->subforum = Forum::create(['slug' => 'sub', 'title' => 'Subforum', 'type' => 'forum', 'parent_id' => $this->forum->id]);
        $this->thread = Topic::create(['slug' => 't1', 'title' => 'Thread', 'forum_id' => $this->forum->id]);
        $this->threadInSub = Topic::create(['slug' => 't2', 'title' => 'Thread in sub', 'forum_id' => $this->subforum->id]);

        $this->global = Scope::global();
        $this->catScope = Scope::category((int) $this->category->id);
        $this->forumScope = Scope::forum((int) $this->forum->id);
        $this->subScope = Scope::forum((int) $this->subforum->id);
        $this->threadScope = Scope::thread((int) $this->thread->id);
        $this->threadInSubScope = Scope::thread((int) $this->threadInSub->id);
    }

    public static function make(): self
    {
        return new self;
    }

    public function group(string $slug, array $attrs = []): Group
    {
        // firstOrCreate so the fixture reuses seeded system/trust groups (e.g. 'members') instead of
        // colliding on the unique slug; ad-hoc test groups are created on demand.
        return $this->groups[$slug] ??= Group::firstOrCreate(
            ['slug' => $slug],
            array_merge(['name' => ucfirst($slug), 'type' => 'custom'], $attrs),
        );
    }

    /**
     * Create a user and attach the given groups. The first slug becomes the primary group unless
     * $primary names one explicitly — primary/secondary must NOT change resolution (security §1.5).
     *
     * @param  array<int,string>  $groups  group slugs
     */
    public function user(array $groups = [], array $attrs = [], ?string $primary = null): User
    {
        $user = User::factory()->create($attrs);

        foreach ($groups as $i => $slug) {
            $isPrimary = $primary !== null ? ($slug === $primary) : ($i === 0);
            $user->groups()->attach($this->group($slug)->id, ['is_primary' => $isPrimary]);
        }

        return $user->fresh();
    }

    /** Write a single three-state ACL entry for a holder (User, Group, or group-slug string). */
    public function grant(User|Group|string $holder, string $permission, Scope $scope, PermissionValue $value): AclEntry
    {
        [$type, $id] = $this->holderRef($holder);

        return AclEntry::create([
            'permission_key' => $permission,
            'holder_type' => $type,
            'holder_id' => $id,
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'value' => $value->value,
        ]);
    }

    public function ban(User $user, ?Scope $scope = null, mixed $expiresAt = null): Ban
    {
        $scope ??= Scope::global();

        return Ban::create([
            'user_id' => $user->id,
            'type' => 'user',
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'expires_at' => $expiresAt,
        ]);
    }

    public function resolver(): PermissionResolver
    {
        return app(PermissionResolver::class);
    }

    public function explain(User $user, string $permission, Scope $scope): Decision
    {
        $this->resolver()->flushMemo(); // each assertion reflects the current DB, not a prior snapshot

        return $this->resolver()->explain($user->fresh(), $permission, $scope);
    }

    public function can(User $user, string $permission, Scope $scope): bool
    {
        $this->resolver()->flushMemo();

        return $this->resolver()->can($user->fresh(), $permission, $scope);
    }

    /**
     * The core assertion: the cached boolean MUST agree with the full trace (the oracle), the verdict
     * must match $expected, and — when given — the decisive $reason must match.
     */
    public function assertDecision(User $user, string $permission, Scope $scope, bool $expected, ?string $reason = null): Decision
    {
        $decision = $this->explain($user, $permission, $scope);

        expect($decision->granted)->toBe($expected, "explain(): {$decision->summary()}");
        expect($this->can($user, $permission, $scope))->toBe(
            $expected,
            "can() must agree with explain() (the oracle) — {$decision->summary()}",
        );

        if ($reason !== null) {
            expect($decision->reason)->toBe($reason, "decisive reason — {$decision->summary()}");
        }

        return $decision;
    }

    /** @return array{0:string,1:int} */
    private function holderRef(User|Group|string $holder): array
    {
        if (is_string($holder)) {
            $holder = $this->group($holder);
        }

        return $holder instanceof User
            ? ['user', (int) $holder->id]
            : ['group', (int) $holder->id];
    }
}
