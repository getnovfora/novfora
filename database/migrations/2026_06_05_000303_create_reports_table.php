<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// User reports → staff dashboard (security §3 / data-model §5). Polymorphic so a post, topic, or user
// can be reported. Resolution is logged here and mirrored to the append-only audit_log.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->nullable(); // the reporting user (nullable: system/auto reports)
            $table->string('reportable_type');
            $table->unsignedBigInteger('reportable_id');
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('open')->index(); // open | resolved | dismissed
            $table->foreignId('handled_by')->nullable();
            $table->string('resolution', 500)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();

            $table->index(['reportable_type', 'reportable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
