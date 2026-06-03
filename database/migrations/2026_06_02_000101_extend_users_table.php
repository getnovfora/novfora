<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('id');
            $table->string('slug')->nullable()->unique()->after('username');
            $table->string('display_name')->nullable()->after('name');
            // trust_level: both an engagement and an anti-spam lever (ADR-0007); trust levels are ACL groups.
            $table->unsignedTinyInteger('trust_level')->default(0)->index()->after('display_name');
            // active | pending | suspended | banned — banned is enforced before ACL resolution (security §1.2 step 1).
            $table->string('status')->default('active')->index()->after('trust_level');
            $table->unsignedInteger('reputation_points')->default(0)->after('status');
            $table->unsignedInteger('post_count')->default(0)->after('reputation_points');
            $table->timestamp('last_active_at')->nullable()->after('post_count');
            $table->string('timezone')->nullable()->after('last_active_at');
            $table->string('locale', 12)->nullable()->after('timezone');
            $table->string('avatar_path')->nullable()->after('locale');
            $table->string('cover_path')->nullable()->after('avatar_path');
            // Multi-tenant seam (ADR-0004): nullable; a global scope no-ops when null. SaaS not built.
            $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('cover_path');
        });
    }

    public function down(): void
    {
        // Drop indexes before columns so SQLite can rebuild the table cleanly (it errors if a dropped
        // column still backs an index). On MySQL dropColumn would cascade these; being explicit keeps
        // the down path portable and the migration fully reversible (tested by the operability suite).
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_unique');
            $table->dropUnique('users_slug_unique');
            $table->dropIndex('users_trust_level_index');
            $table->dropIndex('users_status_index');
            $table->dropIndex('users_tenant_id_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'username', 'slug', 'display_name', 'trust_level', 'status', 'reputation_points',
                'post_count', 'last_active_at', 'timezone', 'locale', 'avatar_path', 'cover_path', 'tenant_id',
            ]);
        });
    }
};
