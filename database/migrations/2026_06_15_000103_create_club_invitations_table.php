<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Phase 4 · M1.3 — Club invitations. A private club is invite-only; an owner/moderator mints a single-use,
// expiring token. The token IS the secret (40 random chars — unguessable), so the accept link needs no
// session; an optional `email` binds the invite to one address. Accepting marks accepted_at/accepted_by.
// Reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();           // unguessable secret carried in the accept link
            $table->string('email')->nullable();             // bind the invite to one address (optional)
            $table->unsignedBigInteger('invited_by')->nullable(); // inviter may later delete their account
            $table->timestamp('expires_at');                 // hard expiry (default 14 days)
            $table->timestamp('accepted_at')->nullable();    // single-use: set on acceptance
            $table->unsignedBigInteger('accepted_by')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'accepted_at']);       // pending-invites listing
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_invitations');
    }
};
