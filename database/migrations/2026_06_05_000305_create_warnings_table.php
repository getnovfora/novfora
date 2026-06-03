<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Issued warnings / infractions (security §3 / data-model §5). Points are time-decaying (`expires_at`);
// live (unexpired) points accumulate toward automated consequences at thresholds. `acknowledged_at`
// supports the IPS "required acknowledgment before posting is restored" flow.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable();
            $table->foreignId('warning_type_id')->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->string('reason', 500)->nullable();
            $table->string('content_type')->nullable();           // optional reference to the offending content
            $table->unsignedBigInteger('content_id')->nullable();
            $table->json('action_taken')->nullable();             // the consequence applied, if any
            $table->timestamp('expires_at')->nullable()->index(); // time-decay; null = never expires
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']); // "live points for this user" query
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warnings');
    }
};
