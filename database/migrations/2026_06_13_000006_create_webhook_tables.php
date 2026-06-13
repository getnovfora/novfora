<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Outbound webhooks (ADR-0033, B3). An admin registers endpoints subscribed to domain events; each event
// enqueues a signed delivery that the cron-driven runner POSTs with retry/backoff — so delivery degrades
// gracefully on the baseline (cron) tier with no persistent worker. The per-endpoint secret signs the body
// (HMAC-SHA256), mirroring the inbound webhook verifier so a receiver verifies the same way.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('url');
            $table->text('secret');                 // encrypted at rest (the HMAC signing key)
            $table->json('events');                 // subscribed domain-event names
            $table->boolean('is_active')->default(true);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->json('payload');
            $table->string('status', 20)->default('pending'); // pending | delivered | failed
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']); // the cron drain query
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
