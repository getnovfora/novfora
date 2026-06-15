<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spike P2 (deliverability) — the durable pending-work ledger for cron-batched digests. Written ADDITIVELY
// by the flag-gated digest path (App\Deliverability\Digest\DigestQueue), NEVER by the live immediate
// notification path. A NULL `digest_run_id` = unclaimed; the assembler atomically flips a bounded batch of
// these to a run id inside one transaction (so a killed tick rolls run + claim back together → no drop,
// no double). Stores only a payload SNAPSHOT (event/thread/actor/url), never rendered HTML. Reversible.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('digest_queue_items')) {
            Schema::create('digest_queue_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('event_type', 40);              // reply | mention | moderation | …
                $table->string('actor_username')->nullable();
                $table->json('payload');                       // {thread_id, topic_title, post_id, url}
                $table->string('cadence', 10);                 // the user's chosen cadence at enqueue time
                $table->char('notification_id', 36)->nullable(); // source DatabaseNotification UUID, when known
                // NULL until claimed; set to the owning run inside the assembler txn. nullOnDelete so deleting a
                // run (e.g. test teardown) un-claims its items rather than cascading them away.
                $table->foreignId('digest_run_id')->nullable()->constrained('digest_runs')->nullOnDelete();
                $table->timestamp('created_at')->nullable();

                // Defensive: the same source event can't be staged twice for one cadence. notification_id is
                // nullable, and MySQL treats NULLs as distinct in a UNIQUE index, so non-notification items are
                // exempt (multiple NULLs allowed).
                $table->unique(['notification_id', 'cadence']);
                $table->index(['user_id', 'cadence', 'digest_run_id']); // due-user / per-user claim scan
                $table->index(['cadence', 'digest_run_id', 'id']);      // cap-bounded ordered claim
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_queue_items');
    }
};
