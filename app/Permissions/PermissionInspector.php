<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Models\User;

/**
 * The "why can / can't this user do X here?" inspector (security §1.4).
 *
 * It runs a full, uncached resolution (the same trace the truth-table suite uses as its oracle) and
 * assembles an explainable report: the verdict, the decisive rule, the scope chain that was walked,
 * the holders that were considered, and every candidate ACL entry that fed the decision.
 */
final class PermissionInspector
{
    public function __construct(private readonly PermissionResolver $resolver) {}

    /**
     * @return array{
     *     user: array{id:int, label:string, status:string},
     *     permission: string,
     *     scope: string,
     *     granted: bool,
     *     reason: string,
     *     decided_at_scope: ?string,
     *     decided_by: ?string,
     *     scope_chain: list<string>,
     *     holders: list<string>,
     *     entries: list<array{holder:string,scope:string,value:string,note?:string}>,
     *     summary: string,
     * }
     */
    public function inspect(User $user, string $permission, Scope $scope): array
    {
        $decision = $this->resolver->explain($user, $permission, $scope);

        return [
            'user' => [
                'id' => (int) $user->getKey(),
                'label' => (string) ($user->username ?? $user->name ?? ('user#'.$user->getKey())),
                'status' => (string) ($user->status ?? 'active'),
            ],
            'permission' => $permission,
            'scope' => $scope->key(),
            'granted' => $decision->granted,
            'reason' => $decision->reason,
            'decided_at_scope' => $decision->decidedAtScope?->key(),
            'decided_by' => $decision->decidedByHolder,
            'scope_chain' => array_map(fn (Scope $s) => $s->key(), ScopeChain::for($scope)),
            'holders' => $this->holders($user),
            'entries' => $decision->trace,
            'summary' => $decision->summary(),
        ];
    }

    /** @return list<string> */
    private function holders(User $user): array
    {
        return array_merge(
            ['user#'.$user->getKey()],
            array_map(fn (int $id) => 'group#'.$id, $user->groupIds()),
        );
    }
}
