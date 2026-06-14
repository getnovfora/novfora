<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Member tool 2.1 — personal bookmarks ("saved" topics + posts). A polymorphic edge from a user to a
// topic/post; one row per (user, target). Private to the owner. Reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookmarks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('bookmarkable_type', 40); // App\Models\Topic | App\Models\Post
            $table->unsignedBigInteger('bookmarkable_id');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'bookmarkable_type', 'bookmarkable_id'], 'bookmarks_owner_target_unique');
            $table->index(['bookmarkable_type', 'bookmarkable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
    }
};
