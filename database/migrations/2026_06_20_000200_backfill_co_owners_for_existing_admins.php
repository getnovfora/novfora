<?php

// SPDX-License-Identifier: Apache-2.0

use App\Admin\AdminCoOwnerService;
use App\Models\AclEntry;
use App\Permissions\AclVersion;
use App\Permissions\PermissionValue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ACP v3 · v3-a (co-owners, ADR-0080) — NON-DESTRUCTIVE-UPGRADE backfill. The companion 000100 migration adds
 * `is_co_owner` defaulting false, and only the INSTALLER crowns a co-owner. So a board that pre-dates v3-a would
 * upgrade to ZERO co-owners — and because the `administrator` preset deliberately WITHHOLDS admin.security.access
 * (co-owner-only), the Security section and co-owner management would become unreachable without manual DB
 * surgery. That breaks the reversible / non-destructive-upgrade mandate.
 *
 * Fix: on upgrade, crown EVERY current `admins`-group member as a co-owner — preserving the pre-v3-a status quo
 * in which every full admin implicitly reached Security. We keep the two COUPLED facts in lockstep, exactly as
 * the installer and {@see AdminCoOwnerService} do:
 *   1. the `is_co_owner` pivot flag — the tier marker the last-owner guard counts (it never feeds the resolver);
 *   2. a per-user admin.security.access GLOBAL ALLOW — what the resolver / Security SFCs actually gate on.
 *
 * Idempotent (the flag is SET, the grant is upserted — re-running re-syncs and never duplicates) and a genuine
 * NO-OP on a FRESH install: migrations run before the installer creates the first admin, so there are no
 * admins-group members to crown yet — the installer's own crowning still applies untouched. Reversible: down()
 * un-crowns the backfilled admins (clears the flag + deletes the grant) BUT honours the runtime LAST-OWNER
 * guard — it keeps the EARLIEST co-owner so no rollback ordering can strand the owner tier (incl. a `--step`
 * rollback that leaves the is_co_owner column live, or a fresh install where the installer's sole crown IS that
 * last owner, making down() a genuine no-op there); 000100's down() then drops the column itself.
 *
 * Query-builder writes (not Eloquent) keep the migration stable against future model drift; the acl_entries
 * write therefore skips {@see AclEntry::booted()}, so AclVersion is bumped EXPLICITLY at the end —
 * mirroring {@see AdminCoOwnerService::deleteSecurityGrant()} — to invalidate every resolved cache.
 */
return new class extends Migration
{
    private const SECURITY_KEY = 'admin.security.access';

    public function up(): void
    {
        $adminsId = DB::table('groups')->where('slug', 'admins')->value('id');
        if ($adminsId === null) {
            return; // no admins group present — nothing to crown
        }

        $memberIds = $this->adminsMemberIds((int) $adminsId);
        if ($memberIds === []) {
            return; // fresh install: the installer crowns the first admin AFTER migrations run
        }

        // 1) Tier marker on every admins membership (SET → idempotent, safely re-runnable).
        DB::table('group_user')->where('group_id', $adminsId)->update(['is_co_owner' => true]);

        // 2) The resolver input: a per-user GLOBAL ALLOW on admin.security.access for each admin, upserted so a
        //    re-run — or an admin the installer already crowned — never duplicates the row.
        $now = now();
        foreach ($memberIds as $userId) {
            if (! $this->hasSecurityGrant($userId)) {
                DB::table('acl_entries')->insert([
                    'permission_key' => self::SECURITY_KEY,
                    'holder_type' => 'user',
                    'holder_id' => $userId,
                    'scope_type' => 'global',
                    'scope_id' => null,
                    'value' => PermissionValue::Allow->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        app(AclVersion::class)->bump(); // any ACL change invalidates every resolved-permission cache
    }

    public function down(): void
    {
        $adminsId = DB::table('groups')->where('slug', 'admins')->value('id');
        if ($adminsId === null) {
            return;
        }

        // Reverse the crown — but mirror the runtime LAST-OWNER guard (AdminCoOwnerService): a rollback must
        // never strand the board with zero owners. Demote every co-owner EXCEPT the earliest (lowest user_id),
        // which keeps its flag + grant so at least one owner retains Security reach under ANY rollback ordering.
        // On a fresh install the installer's sole crown IS that last owner → a genuine no-op here; on a full
        // feature revert 000100's down() then drops the column and the lone surviving grant goes inert.
        $coOwnerIds = DB::table('group_user')
            ->where('group_id', $adminsId)
            ->where('is_co_owner', true)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $toDemote = array_slice($coOwnerIds, 1); // keep the earliest co-owner; reverse the rest
        if ($toDemote === []) {
            return; // zero or one co-owner — nothing to safely reverse (never strand the last owner)
        }

        DB::table('group_user')->where('group_id', $adminsId)
            ->whereIn('user_id', $toDemote)->update(['is_co_owner' => false]);

        DB::table('acl_entries')
            ->where('permission_key', self::SECURITY_KEY)
            ->where('holder_type', 'user')
            ->where('scope_type', 'global')
            ->whereNull('scope_id')
            ->whereIn('holder_id', $toDemote)
            ->delete();

        app(AclVersion::class)->bump(); // any ACL change invalidates every resolved-permission cache
    }

    /** @return list<int> the user ids of every member of the admins group */
    private function adminsMemberIds(int $adminsId): array
    {
        return DB::table('group_user')->where('group_id', $adminsId)
            ->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
    }

    private function hasSecurityGrant(int $userId): bool
    {
        return DB::table('acl_entries')
            ->where('permission_key', self::SECURITY_KEY)
            ->where('holder_type', 'user')
            ->where('holder_id', $userId)
            ->where('scope_type', 'global')
            ->whereNull('scope_id')
            ->exists();
    }
};
