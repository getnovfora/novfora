<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cron-cached crowdsourced blocklist + disposable-email domains (ADR-0007 / data-model §6). This is the
// BASELINE-safe fallback for the registration layer: the live StopForumSpam API is best-effort, but a
// fresh cache (refreshed by cron) means registration checks never hard-depend on an external service.
// Global by design (a shared crowdsourced cache) — no tenant_id. `value` is bounded to 191 chars so the
// composite unique key stays within MySQL's utf8mb4 index limit.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blocklist_cache')) {
            Schema::create('blocklist_cache', function (Blueprint $table) {
                $table->id();
                $table->string('type', 20);             // ip | email | username | email_domain
                $table->string('value', 191);
                $table->string('source', 40)->default('stopforumspam'); // stopforumspam | disposable | manual
                $table->unsignedInteger('confidence')->default(100);    // 0–100; the registration threshold compares against this
                $table->timestamp('expires_at')->nullable()->index();   // null = never expires (e.g. a maintained disposable list)
                $table->timestamps();

                $table->unique(['type', 'value', 'source']);
                $table->index(['type', 'value']); // the registration lookup path
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('blocklist_cache');
    }
};
