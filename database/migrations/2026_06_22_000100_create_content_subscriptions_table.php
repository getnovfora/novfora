<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Topic/forum follow-subscribe (M2, ADR-0097). Polymorphic: a member follows a Topic (notified of new replies)
| or a Forum (notified of new topics). Distinct from member_subscriptions (paid membership tiers) — this is
| CONTENT following. Additive + reversible. The (type,id) index serves the fan-out subscriber lookup.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_subscriptions')) {
            Schema::create('content_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('subscribable_type', 191);
                $table->unsignedBigInteger('subscribable_id');
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();

                $table->unique(['user_id', 'subscribable_type', 'subscribable_id'], 'content_subs_unique');
                $table->index(['subscribable_type', 'subscribable_id']); // fan-out subscriber lookup
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_subscriptions');
    }
};
