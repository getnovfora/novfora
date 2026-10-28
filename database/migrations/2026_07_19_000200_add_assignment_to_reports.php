<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Staff workflow (NOV-127): route an open report to a specific staff member so work isn't duplicated or
// dropped. `assigned_to` + `assigned_at` parallel the existing `handled_by`/`handled_at` columns (an assignment
// is a claim, not a resolution). Reversible: down() drops both columns.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->foreignId('assigned_to')->nullable()->after('handled_at')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn('assigned_at');
        });
    }
};
