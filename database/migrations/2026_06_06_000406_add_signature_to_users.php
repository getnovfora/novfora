<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// User signatures (data-model §1) — stored as canonical content (ADR-0005) with a server-sanitized HTML
// cache, exactly like posts. avatar_path / cover_path already exist from the M1 users extension.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->longText('signature_doc')->nullable()->after('cover_path');     // canonical source
            $table->string('signature_format', 20)->nullable()->after('signature_doc'); // tiptap_json | markdown
            $table->longText('signature_html')->nullable()->after('signature_format');   // sanitized cache
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['signature_doc', 'signature_format', 'signature_html']);
        });
    }
};
