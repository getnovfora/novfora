<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Role presets are reusable bundles of three-state values. They EXPAND into acl_entries on
        // assignment (security §1.1) — they are not a separate evaluation layer.
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_preset')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission_key', 150);
            $table->tinyInteger('value'); // ALLOW = 1 | NO = 0 | NEVER = -1
            $table->timestamps();
            $table->unique(['role_id', 'permission_key']);
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('holder_type', 16); // user | group
            $table->unsignedBigInteger('holder_id');
            $table->string('scope_type', 16)->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->timestamps();
            $table->index(['holder_type', 'holder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
