<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Plugin trust guardrails (ADR-0031 hardening, H3). A module runs with FULL server trust, so installing/enabling
// one is the highest-privilege admin act. These columns record: explicit admin CONSENT to that trust gate, a
// package INTEGRITY hash (sha-256 over the module's files — tamper detection vs the last admin-blessed state),
// and the disable-on-fatal QUARANTINE state (a module that throws while loading is auto-disabled + recorded
// instead of white-screening the site). Additive + reversible.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->timestamp('consented_at')->nullable()->after('enabled');        // admin acknowledged full trust
            $table->string('package_hash', 64)->nullable()->after('consented_at');  // sha-256 of the package files
            $table->timestamp('failed_at')->nullable()->after('package_hash');      // quarantined after a fatal load
            $table->text('last_error')->nullable()->after('failed_at');             // the quarantine reason
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn(['consented_at', 'package_hash', 'failed_at', 'last_error']);
        });
    }
};
