<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Scoped ADMIN API tokens (E1 / NOV-135, ADR-0115). Additive columns on the existing api_tokens table (ADR-0033):
// `scopes` (a JSON list of admin scope strings — null/absent = a plain member token that acts fully as its user)
// and `ip_allowlist` (a JSON list of CIDRs the token may present from — null = any). `expires_at` + `last_used_at`
// already exist. Reversible: down() drops both columns.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->json('scopes')->nullable()->after('abilities');
            $table->json('ip_allowlist')->nullable()->after('scopes');
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->dropColumn(['scopes', 'ip_allowlist']);
        });
    }
};
