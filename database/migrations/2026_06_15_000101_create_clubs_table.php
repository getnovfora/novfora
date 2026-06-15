<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Phase 4 · M1.1 — Clubs (sub-communities). A club is a named, optionally-private space that owns one
// discussion forum (linked in M1.4 via the nullable forum_id pointer + the reciprocal forums.club_id FK).
//
// PRIVACY MODEL (two orthogonal axes, ADR-0047):
//   • privacy ∈ public|closed|private  — drives CONTENT visibility + the join policy:
//       public  → content world-readable, anyone may join.
//       closed  → content members-only, join by request→approve.
//       private → content members-only, join by invite only.
//   • is_listed (bool)                 — drives EXISTENCE/metadata visibility to non-members:
//       a private+unlisted club is the "private-hidden" fence case — its name/existence never leaks.
// Reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clubs', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('tagline')->nullable();        // short one-liner for directory cards
            $table->text('description')->nullable();       // plain text; escaped on render
            $table->string('privacy', 10)->default('public'); // public | closed | private
            $table->boolean('is_listed')->default(true);   // shown in directory/search to non-members
            $table->string('color', 7)->nullable();        // accent hex, e.g. #3b82f6
            $table->string('avatar_path')->nullable();
            $table->string('banner_path')->nullable();

            // The creator/founder. nullOnDelete: deleting the founder's account never destroys the club —
            // ownership lives in club_user (role=owner), and M1.3 ownership-transfer covers the sole-owner case.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // The club's root discussion forum (set in M1.4). Plain pointer — a real FK would be circular
            // because forums.club_id references clubs.id (the forum stack is reused, not duplicated).
            $table->unsignedBigInteger('forum_id')->nullable();

            $table->unsignedInteger('member_count')->default(0); // denormalised active-member tally
            $table->json('settings')->nullable();                // escape hatch (mirrors forums.settings)
            $table->unsignedBigInteger('tenant_id')->nullable()->index(); // dormant multi-tenant seam (ADR-0004)
            $table->timestamps();
            $table->softDeletes();

            $table->index(['privacy', 'is_listed'], 'clubs_visibility_idx');
            $table->index('forum_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clubs');
    }
};
