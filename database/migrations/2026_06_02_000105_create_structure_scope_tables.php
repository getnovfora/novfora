<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Minimal structural nodes so acl_entries can attach at category/forum/thread scopes and the
// resolver's scope chain (global → category → forum → thread) can be tested. FULL forum CRUD,
// content, and the editor are M2 (this is only the scope skeleton, per the M1 scope fence).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('forums')) {
            Schema::create('forums', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->string('slug')->unique();
                $table->string('title');
                $table->string('type')->default('forum'); // category | forum | link
                // Materialized ancestor path (e.g. "/1/4/") + depth → O(depth) scope-chain build (ADR-0004).
                $table->string('path')->default('/')->index();
                $table->unsignedInteger('depth')->default(0);
                $table->integer('position')->default(0);
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('topics')) {
            Schema::create('topics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('forum_id')->constrained()->cascadeOnDelete();
                $table->string('slug');
                $table->string('title');
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();
                $table->index(['forum_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('topics');
        Schema::dropIfExists('forums');
    }
};
