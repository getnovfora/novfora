<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Topic polls (P2-M1) over the pre-built `topics.poll_id` seam. A topic has at most one poll; the poll has
// ordered options each carrying a denormalised `vote_count` recomputed authoritatively from `poll_votes` by
// PollService (drift-free). Vote integrity (Phase-2 amendment #5): the STRUCTURAL floor is
// UNIQUE(poll_option_id, user_id) for BOTH modes (no double-vote on one option). A blanket
// UNIQUE(poll_id, user_id) cannot exist (it would forbid legitimate multi-choice votes), so single-choice's
// "one option per user" and multi-choice's `max_choices` cap are enforced at the app layer by PollService,
// which locks the poll row to serialise concurrent votes.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('polls')) {
            Schema::create('polls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
                $table->string('question');
                $table->boolean('is_multiple')->default(false);
                $table->unsignedInteger('max_choices')->nullable(); // multi-choice cap (null = unlimited among options)
                $table->timestamp('closes_at')->nullable();
                $table->boolean('is_closed')->default(false);
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();

                $table->index(['topic_id']);
            });
        }

        if (! Schema::hasTable('poll_options')) {
            Schema::create('poll_options', function (Blueprint $table) {
                $table->id();
                $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
                $table->string('label');
                $table->unsignedInteger('position')->default(0);
                $table->unsignedInteger('vote_count')->default(0); // denormalised; recomputed authoritatively on each vote
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();

                $table->index(['poll_id', 'position']);
            });
        }

        if (! Schema::hasTable('poll_votes')) {
            Schema::create('poll_votes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
                $table->foreignId('poll_option_id')->constrained()->cascadeOnDelete();
                // Votes are the voter's PII and hard-delete with them (privacy §6); the deletion flow recomputes
                // the affected option tallies (the §6 ADR finalises this before M2 PMs land).
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();

                // Structural floor (amendment #5): a user may vote a given option at most once, in BOTH modes.
                $table->unique(['poll_option_id', 'user_id']);
                $table->index(['poll_id', 'user_id']); // "what did this user vote" + single-choice replace lookup
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_votes');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('polls');
    }
};
