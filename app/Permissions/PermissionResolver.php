<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Models\AclEntry;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The permission-mask resolution engine (ADR-0006), implementing security §1.2 exactly.
 *
 * NO is treated as NEUTRAL/inherit (security §1.1 / §2.3 — ALLOW beats it; use NEVER to hard-deny).
 * The single (i)-vs-(ii) decision point is marked inline below.
 */
final class PermissionResolver
{
    /** @var array<string, Decision> per-request memoization */
    private array $memo = [];

    public function __construct(
        private readonly AclVersion $version,
        private readonly BanChecker $bans,
    ) {}

    /** Cached boolean check (the public API; used by the Gate). Read-through with graceful miss. */
    public function can(User $user, string $permission, Scope $scope): bool
    {
        $key = $this->cacheKey($user, $permission, $scope);

        try {
            return (bool) Cache::remember($key, now()->addMinutes(30), fn () => $this->resolve($user, $permission, $scope)->granted);
        } catch (\Throwable) {
            return $this->resolve($user, $permission, $scope)->granted; // correctness never depends on the cache
        }
    }

    /** Full trace (the inspector + the test oracle). Never uses the cross-request cache. */
    public function explain(User $user, string $permission, Scope $scope): Decision
    {
        return $this->resolve($user, $permission, $scope);
    }

    public function flushMemo(): void
    {
        $this->memo = [];
    }

    private function resolve(User $user, string $permission, Scope $scope): Decision
    {
        $memoKey = $user->getKey().'|'.$permission.'|'.$scope->key();

        return $this->memo[$memoKey] ??= $this->compute($user, $permission, $scope);
    }

    private function compute(User $user, string $permission, Scope $scope): Decision
    {
        // 1. Banned (globally or for this scope) → DENY.
        if ($this->bans->isBanned($user, $scope)) {
            return new Decision(false, 'banned', $scope, 'system', [
                ['holder' => 'system', 'scope' => $scope->key(), 'value' => 'BAN', 'note' => 'banned globally or for this scope'],
            ]);
        }

        // 2. HOLDERS = user ∪ groups (primary + secondary).
        $groupIds = $user->groupIds();

        // 3. SCOPE_CHAIN = global → … → target.
        $chain = ScopeChain::for($scope);

        // 4. Gather entries for these holders + permission, limited to scopes in the chain.
        $entries = AclEntry::query()
            ->where('permission_key', $permission)
            ->where(function ($q) use ($user, $groupIds) {
                $q->where(fn ($q2) => $q2->where('holder_type', 'user')->where('holder_id', $user->getKey()));
                if ($groupIds !== []) {
                    $q->orWhere(fn ($q2) => $q2->where('holder_type', 'group')->whereIn('holder_id', $groupIds));
                }
            })
            ->get()
            ->filter(fn (AclEntry $e) => $this->entryInChain($e, $chain))
            ->values();

        $trace = $entries->map(fn (AclEntry $e) => [
            'holder' => $e->holder_type.'#'.$e->holder_id,
            'scope' => $e->scope_type.':'.($e->scope_id ?? '*'),
            'value' => PermissionValue::from((int) $e->value)->name,
        ])->all();

        // 5. NEVER is absolute — any NEVER across all holders/scopes → DENY (short-circuit).
        $never = $entries->first(fn (AclEntry $e) => (int) $e->value === PermissionValue::Never->value);
        if ($never) {
            return new Decision(
                false, 'never',
                new Scope($never->scope_type, $never->scope_id),
                $never->holder_type.'#'.$never->holder_id,
                $trace,
            );
        }

        // 6. Resolve by precedence, most-specific scope first.
        foreach (array_reverse($chain) as $s) {
            $atScope = $entries->filter(fn (AclEntry $e) => $s->matches($e->scope_type, $e->scope_id));

            // user overrides group at the same scope; an ALLOW grants.
            $userEntry = $atScope->first(fn (AclEntry $e) => $e->holder_type === 'user');
            if ($userEntry && (int) $userEntry->value === PermissionValue::Allow->value) {
                return new Decision(true, 'user_allow', $s, 'user#'.$userEntry->holder_id, $trace);
            }
            // (i)-vs-(ii) DECISION POINT: a user NO here is NEUTRAL — we fall through to groups and then
            //  inherit, rather than denying. Flip this one branch (return DENY on a set NO) for strict-(i).

            // Among groups, ALLOW beats NO (most-permissive); a group ALLOW grants.
            $groupEntries = $atScope->filter(fn (AclEntry $e) => $e->holder_type === 'group');
            if ($groupEntries->isNotEmpty()) {
                $max = (int) $groupEntries->max(fn (AclEntry $e) => (int) $e->value);
                if ($max === PermissionValue::Allow->value) {
                    return new Decision(true, 'group_allow', $s, 'group', $trace);
                }
                // all group entries are NO → neutral → inherit to the parent scope.
            }
            // nothing decisive at this scope → inherit (continue to the parent).
        }

        // 7. Deny-by-default.
        return new Decision(false, 'default', null, null, $trace);
    }

    private function entryInChain(AclEntry $entry, array $chain): bool
    {
        foreach ($chain as $s) {
            if ($s->matches($entry->scope_type, $entry->scope_id)) {
                return true;
            }
        }

        return false;
    }

    private function cacheKey(User $user, string $permission, Scope $scope): string
    {
        return 'novfora.acl.v'.$this->version->current()
            .'.u'.$user->getKey().'.g'.$user->groupSignature()
            .'.'.md5($permission.'|'.$scope->key());
    }
}
