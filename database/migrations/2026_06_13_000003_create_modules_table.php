<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module/plugin registry (ADR-0031, B1). One row per INSTALLED local module (a package an admin placed under
// modules/<vendor>/<name>/). `enabled` toggles whether its provider loads + its migrations are applied;
// `permission_keys` records the catalog keys the module owns so removal can clean them up without touching
// core or other modules' keys. There is no remote/marketplace surface — installation is a filesystem action.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();              // vendor/name (validated, path-safe)
            $table->string('name');
            $table->string('version', 50);                 // the installed module's own semver
            $table->string('api_version', 50);             // the MODULE API constraint the module targets
            $table->boolean('enabled')->default(false);
            $table->json('permission_keys')->nullable();   // catalog keys this module owns (clean removal)
            $table->json('meta')->nullable();              // description/author/provider for display
            $table->timestamp('installed_at')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
