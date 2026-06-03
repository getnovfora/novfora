<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalog of permission keys (defined in code, persisted for reference + the inspector).
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150)->unique();
            $table->string('label');
            $table->string('scope_kind')->default('forum'); // global | category | forum | thread
            $table->string('group')->nullable();            // UI grouping
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // The heart of the model: one row = (holder, permission, scope, three-state value).
        Schema::create('acl_entries', function (Blueprint $table) {
            $table->id();
            $table->string('permission_key', 150);
            $table->string('holder_type', 16);              // user | group
            $table->unsignedBigInteger('holder_id');
            $table->string('scope_type', 16);               // global | category | forum | thread
            $table->unsignedBigInteger('scope_id')->nullable(); // null for global
            $table->tinyInteger('value');                   // ALLOW = 1 | NO = 0 | NEVER = -1
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();

            // Composite index that drives resolution (data-model §9).
            $table->index(
                ['holder_type', 'holder_id', 'scope_type', 'scope_id', 'permission_key'],
                'acl_resolution_idx',
            );
            // "Who can X here?" + scope-targeted lookups.
            $table->index(['scope_type', 'scope_id', 'permission_key'], 'acl_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acl_entries');
        Schema::dropIfExists('permissions');
    }
};
