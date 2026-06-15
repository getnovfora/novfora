<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Admin analytics (ADR-0035, B5). Privacy-conscious by construction: each row is an AGGREGATE count for a day
// (no per-user rows, no IPs, no PII). Computed by the daily `novfora:analytics:rollup` cron on the baseline
// tier; idempotent via UNIQUE(metric_date, metric_key) so a re-run / backfill overwrites cleanly.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_metrics')) {
            Schema::create('daily_metrics', function (Blueprint $table) {
                $table->id();
                $table->date('metric_date');
                $table->string('metric_key', 40);
                $table->unsignedBigInteger('value')->default(0);
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();

                $table->unique(['metric_date', 'metric_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_metrics');
    }
};
