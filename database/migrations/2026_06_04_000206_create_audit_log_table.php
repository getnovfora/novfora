<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Complete, append-only audit trail of staff/system actions (security §3 / data-model §5). Singular table
// name per the data model. Append-only: created_at, no updated_at; an archival/partition candidate (§9).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
