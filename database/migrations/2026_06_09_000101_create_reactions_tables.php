<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Post reactions (P2-M1, data-model §2): XF-style single-choice typed reactions — at most ONE reaction per
// user per post (UNIQUE(post_id,user_id)). `post_reaction_counts` is the denormalised per-type tally the
// thread hot-path reads; it is recomputed authoritatively from `reactions` on every write by ReactionService
// (drift-free, mirroring Post::syncAggregates), so the listing never COUNT(*)s per row. The score weight that
// would consume a reaction is config-only and INERT until reputation lands (Phase-2 amendment #4).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            // Unlike posts (which anonymise on author deletion), a reaction is the reactor's own PII and is
            // hard-deleted with them (privacy §6) — so this is a real FK with cascade; the deletion flow
            // recomputes the affected tallies (an §6 ADR finalises that before M2 PMs land).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // one of config('hearth.reactions.types') keys
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['post_id', 'user_id']); // single-choice: one reaction per user per post
            $table->index(['post_id', 'type']);     // per-type tally recompute
            $table->index(['user_id']);
        });

        Schema::create('post_reaction_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['post_id', 'type']); // one tally row per (post,type)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_reaction_counts');
        Schema::dropIfExists('reactions');
    }
};
