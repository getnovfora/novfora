<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Backfill the denormalised users.post_count. The column has existed since M0 (an unmaintained seam recorded
// in DECISIONS) but nothing wrote to it, so every member showed "0 posts". The Post model now maintains it
// live (atomic ±1 on create / soft-delete / restore); this one-off sets the absolute count from existing
// non-deleted posts so historical content is reflected. It SETs (not increments), so it is idempotent and
// re-running it simply re-syncs any drift. Reversible: down() zeroes the column (its pre-migration state).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Portable correlated subquery (MySQL/MariaDB, PostgreSQL, SQLite). Counts only live posts — the
        // posts.deleted_at IS NULL clause matches the SoftDeletes default scope the live delta also honours,
        // and is consistent with how forums.post_count tracks the same set.
        DB::statement(
            'UPDATE users SET post_count = ('
            .'SELECT COUNT(*) FROM posts WHERE posts.user_id = users.id AND posts.deleted_at IS NULL'
            .')'
        );
    }

    public function down(): void
    {
        // The column predates this migration; reverting just clears the backfilled values.
        DB::table('users')->update(['post_count' => 0]);
    }
};
