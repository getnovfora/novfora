<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spike P2 (deliverability) — per-user digest cadence. Absence of a row = the default 'immediate' (i.e. the
// existing live behaviour — the user is NOT in the digest path, nothing changes). 'daily'/'weekly' opt the
// user into cron-batched digests; 'off' (also what 1-click unsubscribe sets) means no digest mail. The send
// gate (App\Deliverability\SuppressionGate) reads this AND the email_suppressions list at both assembly and
// send time, so an opt-out/suppression after enqueue is still honoured. Reversible; no change to the
// existing notification_preferences / email_suppressions tables.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('digest_preferences')) {
            Schema::create('digest_preferences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('cadence', 10)->default('immediate'); // off | immediate | daily | weekly
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_preferences');
    }
};
