<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P2-M2 — non-VERP bounce manual-review queue (spike-p2-memo §2b / §8). A polled IMAP mailbox WITHOUT VERP
// cannot cryptographically authenticate a sender-supplied recipient, so it must NOT auto-suppress (that would
// be suppression-as-DoS). Instead a permanent-bounce / complaint message is queued here, UNVERIFIED, for a
// staff member to eyeball and suppress (or dismiss) by hand in the ACP. Reversible.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bounce_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('candidate_email');           // UNVERIFIED — taken from untrusted body headers
            $table->string('event_type', 20);            // bounce | complaint
            $table->boolean('permanent')->default(true); // only permanent bounces / complaints are queued
            $table->text('excerpt');                     // bounded snippet for staff context
            $table->string('dedupe_key', 64)->unique();  // hash(email|type|excerpt) — idempotent re-poll
            $table->string('status', 20)->default('pending'); // pending | resolved | dismissed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bounce_reviews');
    }
};
