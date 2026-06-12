<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated user display preferences (P2-M4). Two per-viewer reading preferences previously hardcoded in
 * the controllers: how many posts per thread page, and whether replies read oldest- or newest-first. Both are
 * nullable — a null falls back to the site default (15 / oldest), so existing rows need no backfill and the
 * migration is fully reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('posts_per_page')->nullable()->after('density');
            $table->string('thread_sort', 10)->nullable()->after('posts_per_page');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['posts_per_page', 'thread_sort']);
        });
    }
};
