<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Badges (P2-M5, ADR-0028). `badges` is the ACP-managed catalog: criteria is a small CLOSED-SET JSON
// document ({type: join|post_count|reputation, threshold: N} — validated on save, never evaluated as an
// expression); icon/color are design TOKENS (AA palette / icon-set names), never raw markup. `user_badges`
// holds awards with UNIQUE(user_id, badge_id) as the idempotency key — awards are PERMANENT (not revoked
// when criteria later lapse) and insertOrIgnore-safe under event replays + the recompute cron. Both
// user_badges FKs cascade with their parent rows; the ADR-0025 cascade still deletes explicitly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('criteria');
            $table->string('icon_token', 50)->nullable();
            $table->string('color_token', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->timestamp('awarded_at');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            $table->unique(['user_id', 'badge_id']); // idempotency: one award per (user, badge)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_badges');
        Schema::dropIfExists('badges');
    }
};
