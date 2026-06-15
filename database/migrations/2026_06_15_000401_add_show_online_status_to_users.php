<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presence opt-in (Phase 4 · M4.3). A per-user privacy switch governing whether the member appears in the
 * "who's online" / live presence surfaces. DEFAULT FALSE — security-by-default: a member is invisible until
 * they deliberately opt in. Reversible (drops the column on rollback).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'show_online_status')) {
                $table->boolean('show_online_status')->default(false)->after('last_active_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'show_online_status')) {
                $table->dropColumn('show_online_status');
            }
        });
    }
};
