<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Theme Studio 1.5 — a style theme may bind its own logo / favicon / background image. Stored on the
// public disk (web-accessible, like avatars); only the relative path is kept here. Reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_themes', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('footer_html');
            $table->string('favicon_path')->nullable()->after('logo_path');
            $table->string('background_path')->nullable()->after('favicon_path');
        });
    }

    public function down(): void
    {
        Schema::table('site_themes', function (Blueprint $table): void {
            $table->dropColumn(['logo_path', 'favicon_path', 'background_path']);
        });
    }
};
