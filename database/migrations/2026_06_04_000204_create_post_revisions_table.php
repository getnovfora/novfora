<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Edit history (data-model §2): one row per saved edit of a post, holding the prior canonical source so
// edits are auditable and (later) diffable. Written before a post's canonical is overwritten.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('editor_id')->nullable();
            $table->string('body_format', 20)->default('tiptap_json');
            $table->longText('body_canonical');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_revisions');
    }
};
