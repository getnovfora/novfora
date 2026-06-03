<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Deliverability suppression list (ADR-0014 / data-model §7). Hard-bounced / complained addresses are
// flagged and excluded from future sends to protect sender reputation. Fed by provider webhooks (enhanced)
// or a cron-polled bounce mailbox / manual review (baseline).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('reason', 40)->default('bounce'); // bounce | complaint | manual
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
