<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Phase 4 · M1.4 — Club discussion space. A club owns its discussion through the EXISTING forum/topic/post
// stack: a `forums.club_id` denormalised pointer marks a forum (and the topics/posts under it) as belonging
// to a club. ScopeChain injects the club scope into such a forum's resolution chain (so a club moderator's
// club-scoped power reaches every topic), and the M1.5 visibility gates use this column to hide a private
// club's content. nullOnDelete: hard-deleting a club detaches its forum rather than cascading the discussion.
// Reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forums', function (Blueprint $table): void {
            $table->foreignId('club_id')->nullable()->after('parent_id')->constrained('clubs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('forums', function (Blueprint $table): void {
            $table->dropForeign(['club_id']);
        });
        Schema::table('forums', function (Blueprint $table): void {
            $table->dropColumn('club_id');
        });
    }
};
