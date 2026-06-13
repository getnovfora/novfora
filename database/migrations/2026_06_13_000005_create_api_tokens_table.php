<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Personal API tokens (ADR-0033, B3). A token authenticates an API request AS its owning user — every endpoint
// then authorizes through the EXISTING permission engine, so a token can never do more than the user can. The
// plaintext is shown once and stored only as a sha256 hash (never recoverable); an optional expiry bounds it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique(); // sha256 hex of the plaintext
            $table->json('abilities')->nullable();        // reserved for scope-narrowing; null = act fully as the user
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
