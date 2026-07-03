<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| U-91 / ◆-lite — profile wall (status posts). A distinct content type from forum posts: it lives on a
| USER's wall (profile_user_id), not in a forum, so it carries no topic/forum scope. Body is stored the
| same lossless way as posts (body_canonical = TipTap doc) with a sanitised display cache, and moves through
| the SAME render → moderate → word-filter pipeline (approved|pending). Soft-deletes so a removed status is
| recoverable and never hard-orphans. Reversible.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_posts')) {
            Schema::create('profile_posts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_user_id')->constrained('users')->cascadeOnDelete(); // whose wall
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();          // the author
                $table->string('body_format', 20)->default('tiptap_json');
                $table->json('body_canonical');
                $table->text('body_html_cache');
                $table->text('body_text')->nullable();
                $table->string('approved_state', 20)->default('approved'); // approved | pending
                $table->string('ip_address', 45)->nullable();
                $table->softDeletes();
                $table->timestamps();

                // The wall listing reads newest-first per profile, filtered by approved_state — one bounded index.
                $table->index(['profile_user_id', 'approved_state', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_posts');
    }
};
