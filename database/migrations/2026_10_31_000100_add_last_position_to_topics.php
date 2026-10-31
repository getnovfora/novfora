<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// NF-08 (NOV-151, PR #54 review): a per-topic position counter. Replies allocate their thread position by
// incrementing topics.last_position under the topic row lock they already hold, instead of a locking read of the
// highest post row — which deadlocked against any transaction that locks a post and then the topic (SpamCleaner,
// moderation deletes, account deletion). Backfilled from the highest position ever handed out (trashed posts
// included, so a restore can never collide). Additive and reversible; the reply path self-heals the counter if it
// ever trails the posts (imports write positions directly).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table): void {
            $table->unsignedInteger('last_position')->default(0);
        });

        DB::table('topics')->update([
            'last_position' => DB::raw('(SELECT COALESCE(MAX(posts.position), 0) FROM posts WHERE posts.topic_id = topics.id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table): void {
            $table->dropColumn('last_position');
        });
    }
};
