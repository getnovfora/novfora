<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// U7 (ADR-0103): registered external sites allowed to embed guest-visible widgets. The `key` is a public,
// revocable site identifier (it appears in the consuming page's source); `origin` is the exact
// scheme://host[:port] granted frame-ancestors / CORS for that key. Writes go through App\Embeds\EmbedManager.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embed_sites', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('origin', 255);
            $table->string('key', 48)->unique();
            $table->boolean('is_enabled')->default(true);
            $table->json('widgets')->nullable(); // null = every widget; else an allowlist of widget slugs
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embed_sites');
    }
};
