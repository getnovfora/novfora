<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Theme Studio 1.6 — admin overrides of the sandbox-template contract (ADR-0038). A row exists only when an
// admin has customised/enabled an overridable template; absent = the stock UI (no override renders). The
// `source` is the restricted sandbox-template language (NOT PHP/Blade), validated before it is stored.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('template_key')->unique(); // a TemplateContract key
            $table->text('source');                    // restricted sandbox-template source
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_templates');
    }
};
