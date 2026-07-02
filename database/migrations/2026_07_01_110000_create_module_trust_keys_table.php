<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// U17 (ADR-0104): the registry of trusted ed25519 public keys a signed module package may be verified against.
// A package installs only if an ENABLED key here validates its detached signature. Managed by the audited
// App\Modules\ModuleTrustKeys; the fingerprint (sha-256 of the raw public key, hex) is the human-facing id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_trust_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('public_key', 64);         // base64 ed25519 public key (32 raw bytes -> 44 chars)
            $table->string('fingerprint', 64)->unique(); // sha-256(raw key) hex — the stable id in the UI/audit
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_trust_keys');
    }
};
