<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity feed log (P2-M3, Core) — an append-only, fan-out-on-read stream of community events
 * (topic.created, post.created, react.given). `actor_id` carries NO foreign key: it mirrors `posts.user_id`
 * so the ADR-0025 deletion cascade can pseudonymise it (actor → NULL renders as "[Deleted]") without leaving
 * a dangling pointer. `scope_forum_id` is the per-viewer permission-filter key (VisibleForumIds); a hard
 * forum delete nulls it (nullOnDelete) so such rows then read as unscoped — a documented M3 edge case.
 * No `updated_at` (append-only); no SoftDeletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable(); // NO FK — pseudonymisable per ADR-0025
            $table->string('verb', 50);
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->string('object_type', 100)->nullable();
            $table->unsignedBigInteger('object_id')->nullable();
            $table->foreignId('scope_forum_id')->nullable()->constrained('forums')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['actor_id', 'created_at']);
            $table->index(['scope_forum_id', 'created_at']);
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
