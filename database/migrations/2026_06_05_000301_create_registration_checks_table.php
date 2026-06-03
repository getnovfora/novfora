<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Anti-spam registration audit (ADR-0007 / data-model §6). One row per signup attempt records the
// provider scores and the tri-state decision (allow | flag | block). PII (IP/email) is disclosed in
// the privacy policy and PURGED on a configurable retention window (security §2.6) — `created_at` is
// indexed so the cron purge is cheap. Append-only: no updated_at.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable(); // the created account, if the attempt was allowed/flagged
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('username')->nullable();
            $table->json('provider_scores')->nullable();          // {stopforumspam: {...}, heuristics: {...}, ...}
            $table->string('decision', 20)->default('allow');     // allow | flag | block
            $table->boolean('degraded')->default(false);          // true when a provider was unreachable → local fallback
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamp('created_at')->nullable()->index(); // indexed for the retention purge
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_checks');
    }
};
