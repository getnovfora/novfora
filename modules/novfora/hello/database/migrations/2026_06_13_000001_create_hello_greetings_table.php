<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hello example plugin (ADR-0031) — a reversible module migration, applied when the module is enabled and
// rolled back when it is removed. Records one row per greeted post (a trivial demo of module-owned schema).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hello_greetings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hello_greetings');
    }
};
