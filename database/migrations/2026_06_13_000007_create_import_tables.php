<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Importer support tables (ADR-0034). `import_maps` is the idempotency + resume ledger: one row per imported
// legacy entity (source/kind/source_id → target_id), UNIQUE so a re-run skips what it already created and
// resumes from the last id. `redirects` holds the 301 maps from legacy URL patterns to new canonical paths so
// link equity (and bookmarks) survive the migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_maps', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30);          // e.g. 'phpbb'
            $table->string('kind', 20);            // user | forum | topic | post
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['source', 'kind', 'source_id']); // idempotency key
            $table->index(['source', 'kind']);                // resume / count
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 191)->unique(); // legacy relative path incl. query, e.g. /viewtopic.php?t=5
            $table->string('to_path');
            $table->unsignedSmallInteger('status')->default(301);
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('import_maps');
    }
};
