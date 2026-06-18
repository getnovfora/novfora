<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACP v3 · v3-e (group system, ADR-0083). The approval queue backing the `request` membership model: a user
 * asks to join a request-model group, an admin/approver approves (→ membership attached) or denies. One row
 * per (user, group) — UNIQUE — re-used (updateOrCreate) so a re-request after a denial flips the same row back
 * to pending rather than piling up history. Approving/denying records the decider. Additive table; down()
 * drops it, so a rollback is clean.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('group_join_requests')) {
            return;
        }

        Schema::create('group_join_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            // pending | approved | denied — see App\Models\GroupJoinRequest::STATUS_*.
            $table->string('status')->default('pending');
            // Who approved/denied (nullable; the row is pending until decided). nullOnDelete so a removed
            // admin account doesn't cascade-delete the request history.
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'group_id']);   // one live request per user+group (re-used on re-request)
            $table->index(['group_id', 'status']);      // the per-group pending-queue lookup
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_join_requests');
    }
};
