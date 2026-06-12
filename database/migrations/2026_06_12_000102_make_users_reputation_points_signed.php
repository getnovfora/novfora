<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// users.reputation_points was created unsigned (M0 seam, before the ledger design existed). The ledger's
// SUM(points) can be NEGATIVE — reaction weights include negative types (e.g. 'disagree' = -1) — and an
// unsigned column cannot store it (a strict-mode MySQL write would throw mid-cascade). Reversible: the
// down() restores the unsigned column; any negative value clamps to 0 on the way back down, which is the
// pre-ledger meaning of the column. (Recorded in DECISIONS.md P2-M5.)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('reputation_points')->default(0)->change();
        });
    }

    public function down(): void
    {
        // Clamp first — restoring the unsigned column with a negative value present would throw on
        // strict-mode MySQL and abort the rollback halfway.
        DB::table('users')->where('reputation_points', '<', 0)->update(['reputation_points' => 0]);

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('reputation_points')->default(0)->change();
        });
    }
};
