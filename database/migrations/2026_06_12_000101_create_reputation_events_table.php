<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The reputation LEDGER (P2-M5, ADR-0028). Append-only: one row per (source) award; users.reputation_points
// is the denormalised SUM(points) per recipient, reconciled by novfora:reputation:recompute. The
// UNIQUE(source_type, source_id) is the idempotency key — a source (e.g. a reaction) awards AT MOST ONCE,
// so a double-fired event's insertOrIgnore is a provable no-op. `points` is SIGNED (reaction weights may be
// negative, e.g. 'disagree'). user_id (the RECIPIENT) is a real cascade FK like reactions/poll_votes —
// belt-and-braces under the users-row delete; the AccountDeletionService cascade still deletes explicitly
// (and prunes events SOURCED from the deleted user's reactions, which the FK cannot see).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reputation_events')) {
            Schema::create('reputation_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the RECIPIENT (denorm sum owner)
                $table->string('source_type', 100);                             // polymorphic source (e.g. Reaction)
                $table->unsignedBigInteger('source_id');
                $table->integer('points');                                      // signed weight at award time
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamp('created_at')->nullable();                    // append-only — no updated_at (mirrors activities)

                $table->unique(['source_type', 'source_id']);                   // idempotency: one source, one award
                $table->index(['user_id', 'points']);                           // recipient sums
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reputation_events');
    }
};
