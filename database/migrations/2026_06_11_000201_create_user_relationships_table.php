<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// User relationships (P2-M2 Half-B builds the table + the IGNORE half; the FOLLOW half is M3). A directed
// edge: `user_id` relates to `related_user_id` with a type. IGNORE (Core, wired here): a user who ignores you
// does not receive your PMs and cannot be added to a conversation you start — enforced at the SERVICE layer.
// FOLLOW (M3 — table only, intentionally NOT wired here: no feed/notification routing). `type` is a string(20)
// + model constants rather than a DB ENUM, matching the codebase convention (posts.approved_state,
// reactions.type) for MySQL/PostgreSQL portability and clean reversibility. Both endpoints are real cascade
// FKs: the edge is relationship metadata that hard-deletes with either endpoint (privacy §6, like reactions).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_relationships')) {
            Schema::create('user_relationships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();                  // the actor
                $table->foreignId('related_user_id')->constrained('users')->cascadeOnDelete();   // the target
                $table->string('type', 20);                                                      // follow | ignore (UserRelationship::TYPE_*)
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();

                $table->unique(['user_id', 'related_user_id', 'type']);   // one edge per (actor,target,type)
                $table->index(['related_user_id', 'type']);               // reverse lookup: "who ignores X"
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_relationships');
    }
};
