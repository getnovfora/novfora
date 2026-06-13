<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Staff notes (A1) — private staff-only notes ABOUT a member. The subject FK cascadeOnDeletes (a member's
// notes vanish with them). author_id carries NO foreign key so a note SURVIVES the author's own account
// deletion; the ADR-0025 cascade NULLs it (renders "[Deleted]"), exactly like warnings.issued_by. A note is
// NEVER visible to the subject or to a non-staff viewer — that gate lives in App\Moderation\StaffNotes and is
// enforced through the EXISTING permission engine (bans.manage), never a second permission system.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the subject the note is about
            $table->unsignedBigInteger('author_id')->nullable();             // no FK: pseudonymisable (ADR-0025)
            $table->text('body');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();    // dormant multi-tenancy seam
            $table->timestamps();

            $table->index(['user_id', 'created_at']); // the per-member profile / mod-panel listing
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notes');
    }
};
