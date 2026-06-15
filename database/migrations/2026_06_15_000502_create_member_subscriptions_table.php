<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member subscriptions (Phase 4 · M5.1). A member's grant of a membership tier. `status` drives perk
 * projection: only `active` rows grant their tier's perks (TierProjector re-derives from active rows).
 * `provider` records how it was granted — `manual` (admin marks paid, M5.2, the only live-granting path in
 * this build) or `stripe` (M5.3, charging disabled). `expires_at` is swept hourly by `novfora:tiers:expire`,
 * which flips the row to `expired` and revokes the perks. No card data is ever stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tier_id')->constrained('membership_tiers')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');   // pending | active | expired | cancelled
            $table->string('provider', 30)->default('manual');  // manual | stripe
            $table->string('provider_ref')->nullable();         // external subscription/session id (no card data)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_subscriptions');
    }
};
