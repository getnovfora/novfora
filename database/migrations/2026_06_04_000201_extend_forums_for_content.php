<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M2 extends the minimal M1 forums scope node (data-model §2) with description, lock state, settings,
// denormalised counters, and the last-post pointer. Cross-table pointers are plain unsigned ints (no DB
// FK) to avoid circular references with posts and keep the down path reversible on SQLite.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forums', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->string('icon')->nullable()->after('description');
            $table->string('color')->nullable()->after('icon');
            $table->boolean('is_locked')->default(false)->after('type');
            $table->json('settings')->nullable()->after('position');
            $table->unsignedInteger('topic_count')->default(0)->after('settings');
            $table->unsignedInteger('post_count')->default(0)->after('topic_count');
            $table->unsignedBigInteger('last_post_id')->nullable()->after('post_count');
            $table->unsignedBigInteger('last_topic_id')->nullable()->after('last_post_id');
            $table->timestamp('last_posted_at')->nullable()->after('last_topic_id');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('forums', function (Blueprint $table) {
            $table->dropColumn([
                'description', 'icon', 'color', 'is_locked', 'settings', 'topic_count',
                'post_count', 'last_post_id', 'last_topic_id', 'last_posted_at', 'deleted_at',
            ]);
        });
    }
};
