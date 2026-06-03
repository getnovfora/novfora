<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Attachments (data-model §2 / security §4): typed-allowlist uploads stored OFF the web root, with a
// checksum (importer verification + dedupe), optional image dimensions + thumbnail. disk = local
// (baseline) or s3 (enhanced); post_id is nullable because a file is uploaded before the post is saved.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('checksum', 64)->nullable()->index(); // sha-256 hex
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
