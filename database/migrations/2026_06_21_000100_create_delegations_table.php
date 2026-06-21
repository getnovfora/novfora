<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACP v3 · v3-f (temporary-access delegation, ADR-0087). The source-of-truth + provenance record for "co-owner
 * D handed recipient R the capability K in scope S until expires_at." App\Admin\DelegationService projects each
 * LIVE row into ONE time-boxed `acl_entries` row (holder=user:R, ALLOW, expires_at) — the resolver only ever
 * reads that row, never this table (G1, mirroring the v3-b moderator_assignments / ForumModeratorProjector
 * pattern). `expires_at` is NOT NULL: every delegation is time-boxed (≤ 30 days, capped in the service) and
 * auto-expires via the v3-0 seam (resolver expires_at filter + cache-TTL cap + novfora:acl:prune-expired) with
 * no code here. `revoked_at` records an EARLY revoke (the mirrored acl row is deleted then); the row is kept as
 * audit history. Additive table; down() drops it, so a rollback is clean (G3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delegations')) {
            return;
        }

        Schema::create('delegations', function (Blueprint $table): void {
            $table->id();
            // Who granted it (a co-owner at grant time) and who received it. Both cascade on user deletion so a
            // deleted account leaves no dangling provenance (the mirrored acl_entries row is user-scoped too).
            $table->foreignId('delegator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            // The single delegated capability + its scope, mirroring acl_entries (scope_id NULL = global).
            $table->string('permission_key', 150);
            $table->string('scope_type', 16);
            $table->unsignedBigInteger('scope_id')->nullable();
            // Time-box (NOT NULL — every delegation expires) + an optional early-revoke marker.
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();

            // The two read paths: "what does R hold?" (Active Delegations list + recipient lookups) and
            // "what did D grant?" (the cascade-revoke on a delegator demotion).
            $table->index(['recipient_id', 'expires_at']);
            $table->index(['delegator_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
