<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5.1 — make at-most-once subscription granting a DB invariant rather than a racy application check.
 *
 * The Stripe webhook deduped on a SELECT-then-INSERT (`exists()` then `create()`), with no DB constraint
 * backing it, so two concurrent deliveries of the same signed event could both pass the check and double-grant.
 * A UNIQUE index on (provider, provider_ref) is the authoritative guard; the controller now treats a
 * unique-violation as the duplicate outcome. NULL provider_ref (the manual/offline path's per-grant value) is
 * exempt — MySQL treats NULLs as distinct in a unique index — so manual grants are unaffected.
 *
 * Idempotent + reversible (the upgrade path may re-run migrations): the add is guarded so a re-run is a no-op.
 */
return new class extends Migration
{
    private const INDEX = 'member_subscriptions_provider_provider_ref_unique';

    public function up(): void
    {
        if (! Schema::hasTable('member_subscriptions')) {
            return;
        }

        try {
            Schema::table('member_subscriptions', function (Blueprint $table) {
                $table->unique(['provider', 'provider_ref'], self::INDEX);
            });
        } catch (Throwable) {
            // Index already present (idempotent re-run on the no-SSH upgrade path) — nothing to do.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('member_subscriptions')) {
            return;
        }

        try {
            Schema::table('member_subscriptions', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        } catch (Throwable) {
            // Index already absent — nothing to do.
        }
    }
};
