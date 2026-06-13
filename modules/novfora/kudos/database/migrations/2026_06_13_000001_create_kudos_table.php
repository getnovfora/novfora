<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Plugin-owned table for the first-party Kudos example (one kudos per user per post). Created on enable,
// rolled back on remove by the module lifecycle. The plugin never touches a core table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kudos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at')->nullable();
            $table->unique(['post_id', 'user_id']); // one kudos per user per post
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kudos');
    }
};
