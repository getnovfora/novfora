<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Username history (U8, ADR-0106): one row per admin username change (old → new), written by
| UsernameService BEFORE the users row is overwritten — the post_revisions snapshot ordering.
| Singular table name per the audit_log append-only-log precedent; changed_by mirrors
| post_revisions.editor_id (nullable, no FK constraint). Additive + reversible.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('username_history')) {
            Schema::create('username_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('old_username');
                $table->string('new_username');
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->string('reason', 500)->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('username_history');
    }
};
