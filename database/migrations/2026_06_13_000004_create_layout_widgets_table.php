<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Layout widget placements (ADR-0032, B2). Each row places a widget (by stable key) into a named layout region
// at an ordered position with its own settings. The configurator writes these; the <x-region> outlet reads the
// enabled ones in order. A placement whose widget is no longer registered simply renders nothing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('layout_widgets', function (Blueprint $table) {
            $table->id();
            $table->string('region', 60);
            $table->string('widget_key', 60);
            $table->unsignedInteger('position')->default(0);
            $table->json('settings')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();

            $table->index(['region', 'position']); // the per-region ordered read
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('layout_widgets');
    }
};
