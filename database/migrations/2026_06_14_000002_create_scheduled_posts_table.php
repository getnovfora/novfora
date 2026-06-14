<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Member tool 2.4 — post scheduling (publish-at). A scheduled REPLY is held here (NOT created in the topic)
// until its time; the cron command then creates the real post through PostService so every side-effect
// (counters, last-post pointers, notifications, search index) fires exactly as for a normal reply. Baseline
// cron-tolerant: an atomic claim (published_at) guards against a double-publish under overlapping ticks.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->string('body_format', 20)->default('tiptap_json');
            $table->json('body_canonical');
            $table->timestamp('publish_at');
            $table->timestamp('published_at')->nullable(); // the atomic claim + done-marker
            $table->unsignedBigInteger('post_id')->nullable(); // the created post once published (null = skipped/failed)
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();

            $table->index(['published_at', 'publish_at']); // the due-claim scan
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_posts');
    }
};
