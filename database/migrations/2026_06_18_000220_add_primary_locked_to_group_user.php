<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACP v3 · v3-e (group system, ADR-0083). The primary-group chooser: a user picks their own primary group
 * (rank badge / colour / title under their avatar) from groups they belong to, BUT an admin override takes
 * precedence. `is_primary_locked` on the pivot records that an admin set the primary — while it is set on any
 * of the user's rows, the user's own self-service chooser is refused (the admin choice wins). An admin can
 * clear the lock to hand the choice back. Default false → unchanged behaviour (user choice respected).
 *
 * Primary is COSMETIC: resolution reads ALL of a user's groups (groupIds()), so changing which one is primary
 * never changes effective permissions — this column never feeds the resolver and needs no cache invalidation.
 * Additive; down() drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_user', function (Blueprint $table): void {
            if (! Schema::hasColumn('group_user', 'is_primary_locked')) {
                $table->boolean('is_primary_locked')->default(false)->after('is_primary');
            }
        });
    }

    public function down(): void
    {
        Schema::table('group_user', function (Blueprint $table): void {
            if (Schema::hasColumn('group_user', 'is_primary_locked')) {
                $table->dropColumn('is_primary_locked');
            }
        });
    }
};
