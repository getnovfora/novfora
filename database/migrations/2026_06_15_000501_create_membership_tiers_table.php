<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership tiers (Phase 4 · M5.1). An admin-defined paid (or free) membership level that grants a set of
 * perk permission keys THROUGH the existing engine (App\Membership\TierProjector → acl_entries). Price is
 * stored in minor units; `interval` describes the billing cadence. No money is charged by this table — it is
 * pure catalogue; granting happens via a PaymentProvider (M5.2 manual / M5.3 Stripe) or an admin action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description', 1000)->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('interval', 20)->default('monthly'); // one_time | monthly | yearly
            $table->json('perks')->nullable();                  // list<string> of tier.* perk keys
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_tiers');
    }
};
