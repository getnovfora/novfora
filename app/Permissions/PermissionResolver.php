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
            // Distinguish a genuine miss from a cached `false` with a typed sentinel (a stored verdict is always
            // a bool, so it can never strict-equal the string) — Cache::remember can't express the TTL cap below.
            $sentinel = "\0acl-miss";
            $cached = Cache::get($key, $sentinel);
            if ($cached !== $sentinel) {
                return (bool) $cached;
            }

            $decision = $this->resolve($user, $permission, $scope);
            Cache::put($key, $decision->granted, $this->cacheTtl($decision));

            return $decision->granted;
        } catch (\Throwable) {
            return $this->resolve($user, $permission, $scope)->granted; // correctness never depends on the cache
        }
    }

    /**
     * The cache horizon for a verdict: the normal 30-minute span, but never past the earliest TTL among the rows
     * that fed the decision (ACP v3 · v3-0). A grant lapses by the mere passage of time — which is NOT a write,
     * so it bumps no AclVersion — so without this cap a cached ALLOW could outlive its grant until the next prune.
     * Capping here keeps the expiry filter authoritative on the cached Gate path independent of the prune cron.
     */
    private function cacheTtl(Decision $decision): \DateTimeInterface
    {
        $default = now()->addMinutes(30);
        $until = $decision->cacheUntil;

        return ($until !== null && $until < $default) ? $until : $default;
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
            // 4b. The AUTHORITATIVE expiry filter (ACP v3 · v3-0, ADR-0080 §5). A lapsed grant is never even
            // loaded, so resolution never honours it — defence-in-depth that holds even if the prune cron lags.
            // NULL = never-expire, so every pre-v3 row resolves byte-identically; only a TTL row can drop out.
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get()
            ->filter(fn (AclEntry $e) => $this->entryInChain($e, $chain))
            ->values();

        $trace = $entries->map(function (AclEntry $e) {
            $row = [
                'holder' => $e->holder_type.'#'.$e->holder_id,
                'scope' => $e->scope_type.':'.($e->scope_id ?? '*'),
                'value' => PermissionValue::from((int) $e->value)->name,
            ];

            // Surface a live TTL so the inspector trace shows the expiry rule (ADR-0080 §5). Expired rows are
            // filtered out above, so this annotates the still-valid window of a time-limited grant.
            if ($e->expires_at !== null) {
                $row['expires_at'] = $e->expires_at->toIso8601String();
            }

            return $row;
        })->all();

        // The earliest live TTL among the candidate rows. The cached can() is capped to it (cacheTtl()) so the
        // resolved verdict can never be served past the moment a contributing grant lapses — making the expiry
        // filter authoritative on the cached Gate path too, with no dependence on the prune cron (ADR-0080 §5).
        // A NULL-only set yields null → the normal cache span → byte-identical to pre-v3.
        $cacheUntil = $entries->pluck('expires_at')->filter()->min();

        // 5. NEVER is absolute — any NEVER across all holders/scopes → DENY (short-circuit).
        $never = $entries->first(fn (AclEntry $e) => (int) $e->value === PermissionValue::Never->value);
        if ($never) {
            return new Decision(
                false, 'never',
                new Scope($never->scope_type, $never->scope_id),
                $never->holder_type.'#'.$never->holder_id,
                $trace,
                $cacheUntil,
            );
        }

        // 6. Resolve by precedence, most-specific scope first.
        foreach (array_reverse($chain) as $s) {
            $atScope = $entries->filter(fn (AclEntry $e) => $s->matches($e->scope_type, $e->scope_id));

            // user overrides group at the same scope; an ALLOW grants.
            $userEntry = $atScope->first(fn (AclEntry $e) => $e->holder_type === 'user');
            if ($userEntry && (int) $userEntry->value === PermissionValue::Allow->value) {
                return new Decision(true, 'user_allow', $s, 'user#'.$userEntry->holder_id, $trace, $cacheUntil);
            }
            // (i)-vs-(ii) DECISION POINT: a user NO here is NEUTRAL — we fall through to groups and then
            //  inherit, rather than denying. Flip this one branch (return DENY on a set NO) for strict-(i).

            // Among groups, ALLOW beats NO (most-permissive); a group ALLOW grants.
            $groupEntries = $atScope->filter(fn (AclEntry $e) => $e->holder_type === 'group');
            if ($groupEntries->isNotEmpty()) {
                $max = (int) $groupEntries->max(fn (AclEntry $e) => (int) $e->value);
                if ($max === PermissionValue::Allow->value) {
                    return new Decision(true, 'group_allow', $s, 'group', $trace, $cacheUntil);
                }
                // all group entries are NO → neutral → inherit to the parent scope.
            }
            // nothing decisive at this scope → inherit (continue to the parent).
        }

        // 7. Deny-by-default.
        return new Decision(false, 'default', null, null, $trace, $cacheUntil);
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
