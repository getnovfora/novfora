<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACP v3 · v3-a (co-owners, ADR-0080). The top admin tier: a CO-OWNER is an `admins`-group member whose pivot
 * carries `is_co_owner = true`. Co-owners administer admins and each other and are protected by a last-owner
 * guard (a sole co-owner can never be demoted/removed/deleted — AdminCoOwnerService). The flag is a TIER marker,
 * NOT a permission holder: it never feeds the resolver, so it needs no cache invalidation on its own (the
 * accompanying admin.security.access user-grant is what the resolver reads). The index supports the last-owner
 * guard's locked count of `admins`-group co-owners. Default false → unchanged behaviour. Additive; down() drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_user', function (Blueprint $table): void {
            if (! Schema::hasColumn('group_user', 'is_co_owner')) {
                $table->boolean('is_co_owner')->default(false)->after('is_primary_locked');
                $table->index('is_co_owner');
            }
        });
    }

    public function down(): void
    {
        Schema::table('group_user', function (Blueprint $table): void {
            if (Schema::hasColumn('group_user', 'is_co_owner')) {
                $table->dropIndex(['is_co_owner']);
                $table->dropColumn('is_co_owner');
            }
        });
    }
};
