<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Theme Studio 1.2 — a style theme may carry custom header / footer HTML (a "wrapper" around the board).
// Both are sanitised through the user-content allowlist (ContentSanitizer) BEFORE storage, so what is
// stored is already safe; the layout renders it raw. Reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_themes', function (Blueprint $table): void {
            $table->text('header_html')->nullable()->after('tokens'); // sanitised banner above the page
            $table->text('footer_html')->nullable()->after('header_html'); // sanitised block in the footer
        });
    }

    public function down(): void
    {
        Schema::table('site_themes', function (Blueprint $table): void {
            $table->dropColumn(['header_html', 'footer_html']);
        });
    }
};
