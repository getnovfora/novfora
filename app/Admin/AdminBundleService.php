<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Admin;

use App\Models\AclEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Permissions\AclVersion;
use App\Permissions\MembershipCache;
use App\Permissions\PermissionValue;
use App\Permissions\RoleManager;
use App\Permissions\Scope;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * The Admin Manager (ACP v3 · v3-a, ADR-0080): grant an individual user a SUBSET of ACP sections — a "restricted
 * admin." G10 model: a restricted admin is NOT in the admins group (a full admin already inherits every section
 * via the administrator preset, so a per-user grant could only ever ADD, never SUBTRACT). Instead they hold the
 * umbrella admin.access PLUS their chosen admin.<section>.access keys as PER-USER global grants — DISJOINT rows
 * from the group-holder preset, so this service only ever writes/deletes USER-holder acl_entries and a full admin
 * is never touched. admin.security.access (the co-owner tier) is out of scope here — owned by AdminCoOwnerService.
 *
 * Bundles are STARTING POINTS (AdminBundleSeeder, is_preset roles): assign() copies a bundle's section keys onto
 * the user, then a co-owner per-key toggles with setSectionAccess(). Every grant is ceiling-checked against the
 * actor (RoleManager::assertWithinCeiling): the section keys are Administration-tier, so only a FULL admin holding
 * the key may grant it — a restricted admin (isAdmin() === false) can never assign or escalate (the G10 fence).
 */
final class AdminBundleService
{
    /** The umbrella "can reach the ACP at all" key every restricted admin holds (EnsureSystemPanelAccess gates on it). */
    public const ACCESS_KEY = 'admin.access';

    public function __construct(
        private readonly RoleManager $roles,
        private readonly AclVersion $version,
    ) {}

    /**
     * Apply $bundle to $target as the starting point: their per-user admin grants CONVERGE to EXACTLY admin.access
     * + the bundle's section keys (so this also REPLACES a previous bundle, dropping sections no longer granted).
     * Ceiling-checked against $actor before any write.
     */
    public function assign(User $actor, User $target, Role $bundle): void
    {
        $granted = $this->grantedSetFor($bundle);
        $this->roles->assertWithinCeiling($this->allowMap($granted), $actor, Scope::global());

        DB::transaction(function () use ($actor, $target, $bundle, $granted): void {
            $this->converge((int) $target->getKey(), $granted);
            Audit::log('admin.bundle.assigned', $target, ['by' => (int) $actor->getKey(), 'bundle' => (string) $bundle->slug]);
        });

        MembershipCache::flushFor($target);
    }

    /**
     * Toggle ONE section for $target. Granting adds the section key AND ensures admin.access (panel entry);
     * revoking removes just that section (admin.access + the other sections stay — call revoke() to strip
     * restricted-admin status entirely). Granting is ceiling-checked. $sectionKey must be an assignable
     * admin.<section>.access key (never the co-owner security key).
     *
     * @throws AdminBundleException for a non-assignable section key
     */
    public function setSectionAccess(User $actor, User $target, string $sectionKey, bool $grant): void
    {
        if (! in_array($sectionKey, $this->sectionKeys(), true)) {
            throw new AdminBundleException("“{$sectionKey}” is not an assignable admin section.");
        }

        if ($grant) {
            // grant: ceiling-checked — an Administration-tier key may only be granted by a FULL admin who holds it.
            $this->roles->assertWithinCeiling($this->allowMap([self::ACCESS_KEY, $sectionKey]), $actor, Scope::global());
        } else {
            // revoke: the ceiling fence is grant-shaped and does not run here, so gate the actor explicitly —
            // only a full admin may strip a restricted admin's section (mirrors AdminCoOwnerService::revoke).
            $this->assertActorMayManage($actor);
        }

        DB::transaction(function () use ($actor, $target, $sectionKey, $grant): void {
            $tid = (int) $target->getKey();
            if ($grant) {
                $this->writeGrant($tid, self::ACCESS_KEY); // panel entry — required to use any section
                $this->writeGrant($tid, $sectionKey);
                $this->version->bump();
                Audit::log('admin.section.granted', $target, ['by' => (int) $actor->getKey(), 'section' => $sectionKey]);
            } elseif ($this->deleteGrants($tid, [$sectionKey]) > 0) {
                // Bump + audit only when a grant was actually removed — a no-op revoke writes nothing.
                $this->version->bump();
                Audit::log('admin.section.revoked', $target, ['by' => (int) $actor->getKey(), 'section' => $sectionKey]);
            }
        });

        MembershipCache::flushFor($target);
    }

    /** Strip ALL restricted-admin access from $target (admin.access + every section). A full revoke. Only a full
     *  admin may do this (the actor backstop — revocation is not ceiling-checked, so it is gated explicitly). */
    public function revoke(User $actor, User $target): void
    {
        $this->assertActorMayManage($actor);

        DB::transaction(function () use ($actor, $target): void {
            $removed = $this->deleteGrants((int) $target->getKey(), $this->managedKeys());
            if ($removed > 0) {
                $this->version->bump();
                Audit::log('admin.bundle.revoked', $target, ['by' => (int) $actor->getKey()]);
            }
        });

        MembershipCache::flushFor($target);
    }

    /** Whether $target is a RESTRICTED admin: holds a per-user admin.access grant but is NOT a full (group) admin. */
    public function isRestrictedAdmin(User $target): bool
    {
        return ! $target->isAdmin() && $this->userGrantExists((int) $target->getKey(), self::ACCESS_KEY);
    }

    /**
     * The section keys $target holds as per-user grants — the rail sections a restricted admin sees.
     *
     * @return list<string>
     */
    public function grantedSections(User $target): array
    {
        return AclEntry::query()
            ->where('holder_type', 'user')->where('holder_id', $target->getKey())
            ->where('scope_type', 'global')->whereNull('scope_id')
            ->whereIn('permission_key', $this->sectionKeys())
            ->where('value', PermissionValue::Allow->value)
            ->pluck('permission_key')->values()->all();
    }

    // ── internals ──────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Only a FULL admin (a group member) may administer a restricted admin's access. A restricted admin
     * (isAdmin() === false) can neither grant nor strip — the actor-independent backstop to the Security SFC's
     * mount() gate (Livewire actions skip route middleware), mirroring AdminCoOwnerService::assertActorIsCoOwner.
     * The GRANT paths reach the same bar via RoleManager::assertWithinCeiling's admin-tier rule; this guards the
     * REVOKE paths, where that grant-shaped fence does not run.
     */
    private function assertActorMayManage(User $actor): void
    {
        if (! $actor->isAdmin()) {
            throw new AdminBundleException('Only a full administrator may manage restricted-admin access.');
        }
    }

    /**
     * admin.access + the bundle's section keys (the full per-user grant set the bundle implies). The bundle's keys
     * are filtered to assignable sections — defence so a malformed bundle can never smuggle in admin.access or the
     * co-owner security key.
     *
     * @return list<string>
     */
    private function grantedSetFor(Role $bundle): array
    {
        $sections = $this->sectionKeys();
        $keys = $bundle->permissions()->pluck('permission_key')
            ->filter(fn ($k): bool => in_array($k, $sections, true))
            ->all();

        return array_values(array_unique([self::ACCESS_KEY, ...$keys]));
    }

    /**
     * Converge $target's per-user MANAGED grants to EXACTLY $granted: drop the managed keys not in $granted, write
     * the rest. Key-scoped, so a co-owner's admin.security.access (a different key) is never touched.
     *
     * @param  list<string>  $granted
     */
    private function converge(int $userId, array $granted): void
    {
        $this->deleteGrants($userId, array_values(array_diff($this->managedKeys(), $granted)));
        foreach ($granted as $key) {
            $this->writeGrant($userId, $key);
        }
        $this->version->bump(); // the deletes above are query-builder (skip the AclEntry event, G9) → bump explicitly
    }

    private function writeGrant(int $userId, string $key): void
    {
        AclEntry::updateOrCreate(
            ['permission_key' => $key, 'holder_type' => 'user', 'holder_id' => $userId, 'scope_type' => 'global', 'scope_id' => null],
            ['value' => PermissionValue::Allow->value],
        );
    }

    /**
     * @param  list<string>  $keys
     * @return int rows deleted
     */
    private function deleteGrants(int $userId, array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        return AclEntry::query()
            ->where('holder_type', 'user')->where('holder_id', $userId)
            ->where('scope_type', 'global')->whereNull('scope_id')
            ->whereIn('permission_key', $keys)
            ->delete();
    }

    private function userGrantExists(int $userId, string $key): bool
    {
        return AclEntry::query()
            ->where('permission_key', $key)->where('holder_type', 'user')->where('holder_id', $userId)
            ->where('scope_type', 'global')->whereNull('scope_id')
            ->where('value', PermissionValue::Allow->value)
            ->exists();
    }

    /**
     * @param  list<string>  $keys
     * @return array<string,int>
     */
    private function allowMap(array $keys): array
    {
        return array_fill_keys($keys, PermissionValue::Allow->value);
    }

    /**
     * The assignable section keys: every Administration-tier admin.<section>.access EXCEPT the co-owner-only
     * security key (owned by AdminCoOwnerService — kept on disjoint rows).
     *
     * @return list<string>
     */
    private function sectionKeys(): array
    {
        return Permission::query()
            ->where('group', 'Administration')
            ->where('key', 'like', 'admin.%.access')
            ->where('key', '!=', AdminCoOwnerService::SECURITY_KEY)
            ->pluck('key')->values()->all();
    }

    /**
     * Every key this service owns as a per-user grant: admin.access + the assignable sections.
     *
     * @return list<string>
     */
    private function managedKeys(): array
    {
        return array_values(array_unique([self::ACCESS_KEY, ...$this->sectionKeys()]));
    }
}
