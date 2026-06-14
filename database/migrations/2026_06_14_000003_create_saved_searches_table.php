<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Search 6.1 — a member's saved searches. Stores the human label, the keyword term (for display), and the
// full GET query string (incl. facets/operators) so the search re-runs verbatim. Private to the owner.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('term', 500)->default('');     // the keyword, for display
            $table->string('query_string', 1000)->default(''); // the full GET query, to replay
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
