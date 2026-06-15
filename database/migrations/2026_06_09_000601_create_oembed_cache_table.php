<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// oEmbed resolution cache (P2-M1). Keyed by a sha256 of the URL; stores the TRUSTED rendered HTML (a sandboxed
// iframe for an allowlisted provider, or a link-card facade) so the post-save embed injection — and any
// provider metadata fetch behind it — runs at most once per URL per TTL.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oembed_cache')) {
            Schema::create('oembed_cache', function (Blueprint $table) {
                $table->id();
                $table->string('url_hash', 64)->unique(); // sha256 hex of the source URL
                $table->text('url');
                $table->longText('html');                  // trusted server-rendered embed/facade HTML
                $table->string('provider', 40)->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oembed_cache');
    }
};
