<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('custom'); // system | trust | custom
            $table->string('color')->nullable();
            $table->boolean('is_system')->default(false);
            // priority orders display + tie-breaks promotion; resolution itself is value-based (MAX/NEVER), not priority.
            $table->integer('priority')->default(0)->index();
            $table->json('auto_promotion')->nullable(); // {min_posts, min_trust_level, min_days, ...}
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('group_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false); // primary + N secondary (brief requirement)
            $table->timestamps();
            $table->unique(['group_id', 'user_id']);
            $table->index(['user_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_user');
        Schema::dropIfExists('groups');
    }
};
