<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry v1 (NOV-125 / ADR-0113): provenance of packages installed from the NovFora Registry — so a later
 * publisher REVOCATION in the feed can be surfaced against what is actually installed (the hard part of the
 * spike's GO). Additive + reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registry_installs')) {
            Schema::create('registry_installs', function (Blueprint $table): void {
                $table->id();
                $table->string('slug')->unique();          // vendor/name from the feed
                $table->string('type', 20);                // module | theme
                $table->string('version', 60);
                $table->string('publisher_fingerprint', 64)->index(); // sha-256 hex of the publisher key
                $table->string('target', 120)->nullable(); // module slug, or the SiteTheme id (theme)
                $table->timestamp('installed_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_installs');
    }
};
