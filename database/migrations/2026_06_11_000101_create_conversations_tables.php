<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Multi-participant private messages (P2-M2 Half-B). PMs are the product's first CO-OWNED PII: a
// `conversation` has N participants (conversation_user) and N `messages`. Message bodies reuse the post
// canonical pipeline (body_format / body_canonical / body_html_cache / body_text via ContentRenderer) — there
// is NO second render path. Deletion cascade (ADR-0025): a participant's authored messages are PSEUDONYMISED
// (user_id → NULL, body intact) and their conversation_user rows HARD-deleted; the conversation survives while
// ≥1 participant remains, else it is purged. So `messages.user_id` and `conversations.created_by` follow the
// posts.user_id "anonymisable author" pattern (raw nullable, NO FK — app-layer pseudonymise in
// AccountDeletionService), while conversation_user.* and messages.conversation_id are real cascade FKs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('subject')->nullable();                      // optional thread subject
            $table->unsignedBigInteger('created_by')->nullable();       // starter; anonymisable (no FK, ADR-0025)
            // Millisecond precision (the unread watermark): a strict last_read_at < last_message_at comparison
            // on whole-second columns would treat a message arriving in the SAME second as a markRead as
            // already-read. ms precision makes a read-vs-send tie effectively impossible.
            $table->timestamp('last_message_at', 3)->nullable();        // inbox ordering + unread comparison
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();

            $table->index(['last_message_at']);                         // inbox: order conversations by recency
        });

        Schema::create('conversation_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // A participant row is the actor's own membership record — HARD-deleted with the account (ADR-0025);
            // a real cascade FK (belt-and-braces with the app-layer delete in AccountDeletionService).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at', 3)->nullable();           // unread = last_read_at < conversations.last_message_at (ms precision — see conversations.last_message_at)
            $table->timestamp('left_at')->nullable();                   // soft-leave; non-null = no longer an active participant
            $table->boolean('can_invite')->default(false);              // may add further participants
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);             // one participant row per (conversation,user)
            $table->index(['user_id', 'left_at']);                      // a user's active inbox
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();          // author; anonymisable (no FK, ADR-0025)
            $table->string('body_format', 20)->default('tiptap_json');  // tiptap_json | markdown (post parity)
            $table->longText('body_canonical');                         // lossless source (JSON); ContentRenderer reopens THIS
            $table->longText('body_html_cache')->nullable();            // display HTML, regenerated + sanitized server-side
            $table->longText('body_text')->nullable();                  // tags-stripped text projection
            $table->string('approved_state', 20)->default('approved');  // approved | pending | rejected (M4 PM-queue seam)
            $table->string('ip_address', 45)->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);           // thread order (conversation ≤30 budget)
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_user');
        Schema::dropIfExists('conversations');
    }
};
