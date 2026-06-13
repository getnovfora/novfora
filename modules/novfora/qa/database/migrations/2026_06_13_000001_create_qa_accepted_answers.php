<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Plugin-owned table for the first-party Q&A example (one accepted answer per topic). Created on enable,
// rolled back on remove by the module lifecycle. The plugin never touches a core table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_accepted_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('topic_id')->unique(); // one accepted answer per topic
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('accepted_by')->nullable();
            $table->timestamp('accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_accepted_answers');
    }
};
