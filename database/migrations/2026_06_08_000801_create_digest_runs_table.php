<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spike P2 (deliverability) — the digest idempotency ANCHOR + send receipt. Exactly one row can exist per
// (user, cadence, period_key) thanks to the UNIQUE index below; that committed row — created inside the
// assembler's transaction — is what makes cron-batched digests exactly-once-ASSEMBLED across coarse,
// overlapping, or killed cron ticks (no lock is relied upon for correctness). The `status` lifecycle
// (claimed → built → sent) doubles as the send-job dedup guard; `mailed_at` is the two-phase self-heal flag
// (a `built` run with mailed_at IS NULL was committed but never enqueued → safely re-queued next tick).
// Reversible. Dormant until config('hearth.deliverability.enabled').
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digest_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('cadence', 10);                 // daily | weekly
            $table->string('period_key', 10);              // floored bucket: 2026-06-08 | 2026-W24
            $table->string('status', 12)->default('claimed'); // claimed | built | sent
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamp('mailed_at')->nullable();    // two-phase self-heal: built but not yet enqueued
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // The keystone: one digest per user per cadence-bucket. The DB enforces exactly-once assembly.
            $table->unique(['user_id', 'cadence', 'period_key']);
            $table->index(['status', 'cadence']); // self-heal / re-queue scan
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_runs');
    }
};
