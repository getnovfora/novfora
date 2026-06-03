<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bans are enforced BEFORE ACL resolution (security §1.2 step 1). A ban may be global or scoped.
        Schema::create('bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type')->default('user'); // user | ip | email | range
            $table->string('value')->nullable();      // ip / email / range value for non-user bans
            $table->string('scope_type')->default('global'); // global | category | forum
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'scope_type', 'scope_id']);
            $table->index(['type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bans');
    }
};
