<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Canonical content storage (ADR-0005 / data-model §3): a lossless source-of-truth canonical document,
// a server-(re)generated + sanitized HTML cache, and a plain-text projection for search. Client HTML is
// NEVER trusted; body_html_cache is always rendered from body_canonical server-side (ContentRenderer).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('posts')) {
            Schema::create('posts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->nullable();           // author
                $table->unsignedBigInteger('parent_post_id')->nullable();    // reserved: threaded replies (seam)
                $table->string('body_format', 20)->default('tiptap_json');   // tiptap_json | markdown
                $table->longText('body_canonical');                          // lossless source (JSON); editing reopens THIS
                $table->longText('body_html_cache')->nullable();             // display HTML, regenerated + sanitized server-side
                $table->longText('body_text')->nullable();                   // tags-stripped text projection (search)
                $table->string('ip_address', 45)->nullable();
                $table->string('approved_state', 20)->default('approved');   // approved | pending | rejected
                $table->unsignedInteger('position')->default(0);             // ordinal within the topic
                $table->unsignedInteger('edit_count')->default(0);
                $table->timestamp('edited_at')->nullable();
                $table->unsignedBigInteger('edited_by')->nullable();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['topic_id', 'position']);     // thread view order
                $table->index(['topic_id', 'created_at']);
                $table->index(['user_id']);
                $table->index(['approved_state']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
