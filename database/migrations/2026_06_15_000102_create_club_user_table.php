<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Phase 4 · M1.1 — Club membership edge. One row per (club, user); `role` + `status` are the SOURCE OF
// TRUTH for who belongs to a club and in what capacity. M1.2 PROJECTS these roles into club-scoped
// acl_entries so the permission engine resolves a club moderator's `topic.moderate` at club scope.
//
//   role   ∈ owner | moderator | member          (club-local rank; never out-ranks global staff — ActorRank)
//   status ∈ active | pending | invited | banned  (pending = awaiting approval; invited = awaiting accept)
//
// Reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member');     // owner | moderator | member
            $table->string('status', 20)->default('active');   // active | pending | invited | banned
            $table->unsignedBigInteger('invited_by')->nullable(); // plain pointer; inviter may delete their account
            $table->timestamp('joined_at')->nullable();        // set when status first becomes active
            $table->timestamps();

            $table->unique(['club_id', 'user_id'], 'club_user_unique');
            $table->index(['club_id', 'role', 'status'], 'club_user_roster_idx'); // roster + owner/mod lookups
            $table->index(['user_id', 'status'], 'club_user_membership_idx');      // "my clubs" + visibility gate
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_user');
    }
};
