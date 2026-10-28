<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry v1 (NOV-125 / ADR-0113) — DURABLE registry state. The anti-rollback sequence floor MUST NOT live
 * in the (flushable) cache: a routine `cache:clear` would reset it to 0 and re-enable replay of an older
 * signed feed (apex finding). This tiny key/value row survives cache clears. Additive + reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registry_state')) {
            Schema::create('registry_state', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('value', 255);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_state');
    }
};
