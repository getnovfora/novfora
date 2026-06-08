<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spike P2 (deliverability) — webhook replay/idempotency ledger. An HMAC-verified provider bounce/complaint
// webhook records the provider's event id (or, absent one, a hash of the raw body) here under a UNIQUE
// index; a duplicate POST (provider retry / replay) is recognised and acknowledged WITHOUT re-processing,
// so suppression is applied at most once per real event. `event_key` capped at 191 chars to stay within
// the utf8mb4 single-column index limit on older MySQL. Reversible.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('event_key', 191)->unique(); // provider event id, or sha256 of the raw body
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_webhook_events');
    }
};
