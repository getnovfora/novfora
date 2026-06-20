<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACP v3 · v3-b (per-forum moderators, ADR-0085). Source-of-truth for "holder H moderates forum F with
 * capability set C". App\Permissions\ForumModeratorProjector mirrors each row into forum-scope acl_entries —
 * the resolver only ever reads those, never this table (G1). ONE assignment per (holder, forum) — UNIQUE — so
 * re-assigning swaps the role/bundle in place (the projector key-scope-clears the old set first, G10). A row
 * names EITHER a seeded preset bundle (`bundle` slug) OR a custom role (`role_id`), never both. Additive
 * table; down() drops it, so a rollback is clean (G3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('moderator_assignments')) {
            return;
        }

        Schema::create('moderator_assignments', function (Blueprint $table): void {
            $table->id();
            // Polymorphic holder, mirroring acl_entries.holder_* (no FK — 'user' | 'group').
            $table->string('holder_type');
            $table->unsignedBigInteger('holder_id');
            $table->foreignId('forum_id')->constrained()->cascadeOnDelete();
            // A custom role (is_preset=false, the v3-d builder's output). NULL for a seeded preset bundle.
            // cascadeOnDelete: deleting the custom role (RoleManager::delete retracts its acl_entries) drops the
            // now-meaningless assignment row with it.
            $table->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            // Seeded preset bundle slug (forum-mod-full | forum-mod-content | forum-mod-queue). NULL for a custom role.
            $table->string('bundle')->nullable();
            $table->timestamps();

            // One assignment per holder per forum (re-used via updateOrCreate on re-assign).
            $table->unique(['holder_type', 'holder_id', 'forum_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderator_assignments');
    }
};
