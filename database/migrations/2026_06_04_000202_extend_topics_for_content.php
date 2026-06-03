<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M2 extends the minimal M1 topics scope node (data-model §2): author, type/status, pin flag, first/last
// post pointers, counters, moderation approved_state, and reserved seam columns (prefix/poll/moved).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('forum_id');     // author
            $table->string('type', 20)->default('normal')->after('title');            // normal|sticky|announcement
            $table->string('status', 20)->default('open')->after('type');             // open|locked|moved|merged
            $table->boolean('is_pinned')->default(false)->after('status');
            $table->string('approved_state', 20)->default('approved')->after('is_pinned'); // approved|pending|rejected
            $table->unsignedBigInteger('first_post_id')->nullable()->after('approved_state');
            $table->unsignedBigInteger('last_post_id')->nullable()->after('first_post_id');
            $table->unsignedBigInteger('last_post_user_id')->nullable()->after('last_post_id');
            $table->timestamp('last_posted_at')->nullable()->after('last_post_user_id');
            $table->unsignedInteger('reply_count')->default(0)->after('last_posted_at');
            $table->unsignedInteger('view_count')->default(0)->after('reply_count');
            // Reserved seams (data-model §2) — columns only; the features are later milestones.
            $table->unsignedBigInteger('prefix_id')->nullable()->after('view_count');
            $table->unsignedBigInteger('poll_id')->nullable()->after('prefix_id');
            $table->unsignedBigInteger('moved_to_topic_id')->nullable()->after('poll_id');
            $table->softDeletes();

            // Forum listing hot path (data-model §9): pinned first, then most-recently-posted.
            $table->index(['forum_id', 'is_pinned', 'last_posted_at'], 'topics_forum_listing_idx');
            $table->index(['user_id'], 'topics_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->dropIndex('topics_forum_listing_idx');
            $table->dropIndex('topics_user_idx');
        });

        Schema::table('topics', function (Blueprint $table) {
            $table->dropColumn([
                'user_id', 'type', 'status', 'is_pinned', 'approved_state', 'first_post_id',
                'last_post_id', 'last_post_user_id', 'last_posted_at', 'reply_count', 'view_count',
                'prefix_id', 'poll_id', 'moved_to_topic_id', 'deleted_at',
            ]);
        });
    }
};
