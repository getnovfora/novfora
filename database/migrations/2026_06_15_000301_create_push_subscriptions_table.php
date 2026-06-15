<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Phase 4 · M3.2 — Web Push subscriptions. One row per browser/device a user opted in from (the existence of
// a row IS the opt-in). `endpoint` is the push service URL; `public_key` (p256dh) + `auth_token` are the
// per-subscription keys used to encrypt the payload. A dead subscription (HTTP 410/404 on send) is pruned.
// Reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint', 1024);
            $table->string('public_key', 191);     // p256dh
            $table->string('auth_token', 191);
            $table->string('content_encoding', 32)->default('aes128gcm');
            $table->timestamps();

            // One subscription per endpoint hash (the full endpoint can exceed an index length, so a hash col).
            $table->string('endpoint_hash', 64)->unique();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
