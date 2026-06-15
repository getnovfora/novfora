<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site settings store (ACP v1, PART 0). One row per overridden setting key; the absence of a row means
 * "fall back to env()/config default" — defaults are NOT persisted here (see ADR-0023), so a panel
 * override survives a re-deploy while an unset key keeps tracking the host's env/config. The Settings
 * service reads the whole bag once per request (cached as primitives — RH-9), so this table is never on
 * a hot read path. Reversible: down() drops it cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                // The stored value, always serialised to a string (scalars as-is, arrays as JSON, secrets as
                // ciphertext). Decoding/decrypting happens AFTER the cache boundary in the Settings service so
                // no object — and no plaintext secret — ever enters the cache store.
                $table->longText('value')->nullable();
                // The registry-declared data type for decoding: string | int | float | bool | array.
                $table->string('type', 20)->default('string');
                // Secret-at-rest marker: value is Crypt::encryptString(...) and is masked in audit logs and forms.
                $table->boolean('is_encrypted')->default(false);
                // Tenancy seam only (nullable), consistent with the rest of the schema (ADR-0004).
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
