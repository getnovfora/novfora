<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Idempotency ledger for the Admin API (E1 / NOV-135, ADR-0115). A mutating request may carry an
// `Idempotency-Key` header; the first time (token, key) is seen the response is stored, and any replay within the
// TTL returns the stored response verbatim instead of re-running the mutation — automation-safe on flaky cron/CI
// callers. The UNIQUE (api_token_id, idempotency_key) is the authoritative at-most-once guard (a race loses at the
// index, mirroring the Stripe-webhook idiom). Append-only; pruned by TTL. Reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_token_id')->constrained('api_tokens')->cascadeOnDelete();
            $table->string('idempotency_key', 191);
            $table->string('method', 10);
            $table->string('path', 255);
            $table->unsignedSmallInteger('response_status');
            $table->longText('response_body');
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();
            $table->unique(['api_token_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
