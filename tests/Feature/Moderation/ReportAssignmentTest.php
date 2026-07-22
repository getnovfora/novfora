<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Post;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| NOV-127 — staff workflow: assign / claim / unassign an open report. Gated on bans.manage; an assignee must be
| a report handler (bans.manage). The mod dashboard surfaces each staff member's personal workload.
*/

uses(RefreshDatabase::class);

function openReport(): Report
{
    return Report::create([
        'reporter_id' => Users::inGroups(['members'], ['username' => 'rep-er', 'email' => 'rep@t.test'])->id,
        'reportable_type' => Post::class,
        'reportable_id' => 1,
        'reason' => 'spam',
        'status' => 'open',
    ]);
}

it('lets staff assign, claim, and unassign a report (audited)', function () {
    $this->seed();
    $mod = Users::inGroups(['moderators']);
    $other = Users::inGroups(['moderators'], ['username' => 'mod2', 'email' => 'mod2@t.test']);
    $report = openReport();

    // Assign to another staff member.
    $this->actingAs($mod)->post(route('reports.assign', $report), ['assigned_to' => $other->id])->assertRedirect();
    expect($report->fresh()->assigned_to)->toBe($other->id)
        ->and($report->fresh()->assigned_at)->not->toBeNull();

    // Claim (assign to self).
    $this->actingAs($mod)->post(route('reports.assign', $report), ['assigned_to' => $mod->id])->assertRedirect();
    expect($report->fresh()->assigned_to)->toBe($mod->id);

    // Release (unassign).
    $this->actingAs($mod)->post(route('reports.assign', $report), [])->assertRedirect();
    expect($report->fresh()->assigned_to)->toBeNull()
        ->and($report->fresh()->assigned_at)->toBeNull();
});

it('refuses to assign a report to a non-staff account (422)', function () {
    $this->seed();
    $mod = Users::inGroups(['moderators']);
    $member = Users::inGroups(['members']);
    $report = openReport();

    $this->actingAs($mod)->post(route('reports.assign', $report), ['assigned_to' => $member->id])->assertStatus(422);
    expect($report->fresh()->assigned_to)->toBeNull();
});

it('gates assignment behind bans.manage (403 for a plain member)', function () {
    $this->seed();
    $member = Users::inGroups(['members']);
    $report = openReport();

    $this->actingAs($member)->post(route('reports.assign', $report), ['assigned_to' => $member->id])->assertForbidden();
    expect($report->fresh()->assigned_to)->toBeNull();
});

it('refuses to (re)assign a resolved report (422 — open-only, guards a crafted POST)', function () {
    $this->seed();
    $mod = Users::inGroups(['moderators']);
    $report = openReport();
    $report->update(['status' => 'resolved']);

    $this->actingAs($mod)->post(route('reports.assign', $report), ['assigned_to' => $mod->id])->assertStatus(422);
    expect($report->fresh()->assigned_to)->toBeNull();
});

it('shows a staff member their personal workload on the mod dashboard', function () {
    $this->seed();
    $mod = Users::inGroups(['moderators']);
    $report = openReport();
    $report->update(['assigned_to' => $mod->id, 'assigned_at' => now()]);

    $this->actingAs($mod)->get(route('moderation.dashboard'))
        ->assertOk()
        ->assertSee('My workload')
        ->assertSee('1 report assigned to you');
});
