<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ACP v2 — member-group manager + staff/group colours. The `groups.color` column already exists (an M1 seam,
// 2026_06_02 create_groups_tables) and now holds a named palette KEY (App\Support\GroupColor); this migration
// only adds the optional `description` shown in the group manager. Neither feeds permission resolution (it is
// value-based over acl_entries), so both are admin-editable — unlike the structural slug/type/priority/is_system.
// Reversible.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
