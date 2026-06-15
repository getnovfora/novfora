<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prefixes')) {
            Schema::create('prefixes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('forum_id')->nullable()->constrained()->nullOnDelete(); // null = global
                $table->string('label');
                $table->string('color_token')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();

                $table->index(['forum_id', 'position']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prefixes');
    }
};
