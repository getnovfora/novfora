<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Editor autosave drafts (P2-M1). One draft per (user, context) — context is a coarse compose surface:
//   'topic'  + forum_id  → a new-topic draft in that forum
//   'reply'  + topic_id   → a reply draft in that topic
// OWN-ONLY by construction: every read/write keys on the authenticated user_id + context, and no draft id is
// ever exposed to the client, so a user can only ever touch their own draft. UNIQUE keeps it idempotent.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('post_drafts')) {
            Schema::create('post_drafts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('context_type', 20);          // topic | reply (extensible)
                $table->unsignedBigInteger('context_id');     // forum_id (topic) | topic_id (reply)
                $table->string('body_format', 20)->default('tiptap_json');
                $table->longText('body_canonical');           // the lossless TipTap doc (JSON)
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();

                $table->unique(['user_id', 'context_type', 'context_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('post_drafts');
    }
};
