<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Canned / stock moderator replies (T1). A titled, reusable canonical-JSON reply body a moderator can drop into
| the composer. Additive + reversible.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('canned_replies')) {
            Schema::create('canned_replies', function (Blueprint $table) {
                $table->id();
                $table->string('title', 120);
                $table->json('body_canonical');         // the lossless TipTap doc (rendered via the post pipeline)
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_replies');
    }
};
