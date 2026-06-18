<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACP v3 · v3-0 engine seam (ADR-0080 §5). Add a nullable, indexed `expires_at` to `acl_entries` so a grant can
 * carry a TTL (temporary-access delegation, v3-f, rides this one column). The change is purely ADDITIVE: an
 * existing row has `expires_at = NULL` = never-expire = byte-identical resolution. The resolver gains a single
 * authoritative filter (`expires_at IS NULL OR expires_at > now`) and a cron prune hard-deletes lapsed rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('acl_entries', 'expires_at')) {
            return; // idempotent — already applied
        }

        Schema::table('acl_entries', function (Blueprint $table) {
            // Nullable = the never-expire default; NULL rows resolve exactly as before this migration.
            $table->timestamp('expires_at')->nullable()->after('value');

            // Resolver read path: the holder + permission equality PREFIX is the useful part of this composite —
            // it locates a holder's rows for the queried permission. expires_at trails only as a covering column;
            // the resolver's `expires_at IS NULL OR expires_at > now` is an OR/range predicate the engine cannot
            // satisfy as an index range, so it post-filters the located rows (cheap — a holder has few rows per
            // permission). The plain index below, not this trailing column, is what serves the sweep.
            $table->index(['holder_type', 'holder_id', 'permission_key', 'expires_at'], 'acl_holder_expiry_idx');

            // The prune sweep scans `WHERE expires_at <= now()`; this plain index keeps it off a full-table scan.
            $table->index('expires_at', 'acl_expires_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('acl_entries', 'expires_at')) {
            return;
        }

        // Drop the indexes first (a column under an index cannot be dropped on SQLite's table rebuild), then the
        // column — in separate statements so the SQLite rebuild never sees a dangling index reference.
        Schema::table('acl_entries', function (Blueprint $table) {
            $table->dropIndex('acl_holder_expiry_idx');
            $table->dropIndex('acl_expires_idx');
        });

        Schema::table('acl_entries', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
