<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Phase 4 · M2.1/M2.2 — OAuth social identities. One row links a local user to ONE identity at ONE provider.
//   • unique(provider, provider_user_id) — a given provider identity maps to at most one local account
//     (so a second login with the same Google/GitHub/Discord account always resolves the same user, never a
//     duplicate, and never a silent merge onto a different account).
//   • unique(user_id, provider) — a user links at most one account per provider.
// Reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);            // google | github | discord
            $table->string('provider_user_id', 191);   // the provider's stable account id
            $table->string('nickname')->nullable();     // display handle at the provider (info only)
            $table->string('avatar', 1024)->nullable(); // provider avatar URL (info only)
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id'], 'social_provider_identity_unique');
            $table->unique(['user_id', 'provider'], 'social_user_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
