<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Account\AccountDeletionService;
use App\Models\AuditLog;
use App\Models\StaffNote;
use App\Models\User;
use App\Moderation\StaffNotes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Staff notes (A1): private staff-only notes ABOUT a member. The governing rule is "staff-only, never the
| subject" — enforced by ONE authority (App\Moderation\StaffNotes) through the existing permission engine
| (bans.manage). These pins cover the authority predicates, the profile visibility, the SFC CRUD + audit, the
| author/admin manage split, and the ADR-0025 author de-identification on account deletion.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

// ── Authority: the single source of truth for "who may see / manage" ──────────────────────────────────

it('shows notes to staff about another member, never the subject or a non-staff viewer', function () {
    $mod = Users::inGroups(['moderators']);
    $member = Users::inGroups(['members', 'tl1']);
    $subject = Users::inGroups(['members', 'tl1']);

    expect(StaffNotes::visibleTo($mod, $subject))->toBeTrue()       // staff → another member
        ->and(StaffNotes::visibleTo($mod, $mod))->toBeFalse()       // never about yourself…
        ->and(StaffNotes::visibleTo($member, $subject))->toBeFalse() // …never a non-staff viewer
        ->and(StaffNotes::visibleTo(null, $subject))->toBeFalse();   // …never a guest
});

it('lets the author or an admin manage a note, but not another moderator', function () {
    $author = Users::inGroups(['moderators']);
    $otherMod = Users::inGroups(['moderators']);
    $admin = Users::inGroups(['admins']);
    $subject = Users::inGroups(['members', 'tl1']);
    $note = StaffNote::create(['user_id' => $subject->id, 'author_id' => $author->id, 'body' => 'keep an eye out']);

    expect(StaffNotes::canManage($author, $note))->toBeTrue()    // the author
        ->and(StaffNotes::canManage($admin, $note))->toBeTrue()  // any admin
        ->and(StaffNotes::canManage($otherMod, $note))->toBeFalse() // a different moderator may not
        ->and(StaffNotes::canManage(null, $note))->toBeFalse();

    // Once the author is de-identified, only an admin may manage it.
    $note->update(['author_id' => null]);
    expect(StaffNotes::canManage($otherMod, $note))->toBeFalse()
        ->and(StaffNotes::canManage($admin, $note))->toBeTrue();
});

// ── Profile visibility (the rendered surface) ─────────────────────────────────────────────────────────

it('renders the staff-notes panel only for a staff viewer of another member', function () {
    $mod = Users::inGroups(['moderators']);
    $member = Users::inGroups(['members', 'tl1']);
    $subject = Users::inGroups(['members', 'tl1'], ['username' => 'subjectuser']);

    // Staff viewing another member → panel present.
    $this->actingAs($mod)->get(route('profiles.show', $subject))
        ->assertOk()->assertSee('dusk="staff-notes"', false);

    // Non-staff member → no panel.
    $this->actingAs($member)->get(route('profiles.show', $subject))
        ->assertOk()->assertDontSee('dusk="staff-notes"', false);

    // The subject viewing their OWN profile → no panel, even though notes about them may exist.
    $this->actingAs($subject)->get(route('profiles.show', $subject))
        ->assertOk()->assertDontSee('dusk="staff-notes"', false);

    // Guest → no panel.
    $this->get(route('profiles.show', $subject))
        ->assertOk()->assertDontSee('dusk="staff-notes"', false);
});

it('never leaks a note body to the subject on their own profile', function () {
    $mod = Users::inGroups(['moderators']);
    $subject = Users::inGroups(['members', 'tl1']);
    StaffNote::create(['user_id' => $subject->id, 'author_id' => $mod->id, 'body' => 'SECRET-WATCHLIST-MARKER']);

    $this->actingAs($subject)->get(route('profiles.show', $subject))
        ->assertOk()->assertDontSee('SECRET-WATCHLIST-MARKER');
});

// ── SFC CRUD + audit (acting as staff) ────────────────────────────────────────────────────────────────

it('lets staff add a note, which is persisted and audited', function () {
    $mod = Users::inGroups(['moderators']);
    $subject = Users::inGroups(['members', 'tl1']);
    $this->actingAs($mod);

    Livewire::test('moderation.staff-notes', ['subjectId' => $subject->id])
        ->set('body', 'first contact looked spammy')
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('body', '');

    $note = StaffNote::where('user_id', $subject->id)->firstOrFail();
    expect($note->author_id)->toBe($mod->id)
        ->and($note->body)->toBe('first contact looked spammy')
        ->and(AuditLog::where('action', 'staff_note.created')->count())->toBe(1);
});

it('validates that a note body is required', function () {
    $mod = Users::inGroups(['moderators']);
    $subject = Users::inGroups(['members', 'tl1']);
    $this->actingAs($mod);

    Livewire::test('moderation.staff-notes', ['subjectId' => $subject->id])
        ->set('body', '')
        ->call('add')
        ->assertHasErrors(['body' => 'required']);

    expect(StaffNote::count())->toBe(0);
});

it('lets the author edit and delete their own note', function () {
    $mod = Users::inGroups(['moderators']);
    $subject = Users::inGroups(['members', 'tl1']);
    $note = StaffNote::create(['user_id' => $subject->id, 'author_id' => $mod->id, 'body' => 'original']);
    $this->actingAs($mod);

    Livewire::test('moderation.staff-notes', ['subjectId' => $subject->id])
        ->call('startEdit', $note->id)
        ->assertSet('editingId', $note->id)
        ->set('editBody', 'revised')
        ->call('saveEdit')
        ->assertHasNoErrors();
    expect($note->fresh()->body)->toBe('revised')
        ->and(AuditLog::where('action', 'staff_note.updated')->count())->toBe(1);

    Livewire::test('moderation.staff-notes', ['subjectId' => $subject->id])
        ->call('delete', $note->id);
    expect(StaffNote::find($note->id))->toBeNull()
        ->and(AuditLog::where('action', 'staff_note.deleted')->count())->toBe(1);
});

it('forbids a moderator from editing or deleting another moderator’s note', function () {
    $author = Users::inGroups(['moderators']);
    $otherMod = Users::inGroups(['moderators']);
    $subject = Users::inGroups(['members', 'tl1']);
    $note = StaffNote::create(['user_id' => $subject->id, 'author_id' => $author->id, 'body' => 'hands off']);
    $this->actingAs($otherMod);

    Livewire::test('moderation.staff-notes', ['subjectId' => $subject->id])
        ->call('delete', $note->id)
        ->assertForbidden();
    expect(StaffNote::find($note->id))->not->toBeNull();
});

it('lets an admin delete any staff note', function () {
    $author = Users::inGroups(['moderators']);
    $admin = Users::inGroups(['admins']);
    $subject = Users::inGroups(['members', 'tl1']);
    $note = StaffNote::create(['user_id' => $subject->id, 'author_id' => $author->id, 'body' => 'admin override']);
    $this->actingAs($admin);

    Livewire::test('moderation.staff-notes', ['subjectId' => $subject->id])
        ->call('delete', $note->id);
    expect(StaffNote::find($note->id))->toBeNull();
});

it('forbids a non-staff member from mounting the component', function () {
    $member = Users::inGroups(['members', 'tl1']);
    $subject = Users::inGroups(['members', 'tl1']);
    $this->actingAs($member);

    // The mount() guard aborts 403; Livewire surfaces it as a forbidden response (no note state is exposed).
    Livewire::test('moderation.staff-notes', ['subjectId' => $subject->id])->assertForbidden();
});

// ── ADR-0025: deleting the author de-identifies the note but keeps it ─────────────────────────────────

it('de-identifies a deleted author yet keeps the note (ADR-0025 cascade)', function () {
    $admin = Users::inGroups(['admins']);
    $author = Users::inGroups(['moderators']);
    $subject = Users::inGroups(['members', 'tl1']);
    $note = StaffNote::create(['user_id' => $subject->id, 'author_id' => $author->id, 'body' => 'authored note survives']);

    $this->actingAs($admin);
    app(AccountDeletionService::class)->deleteAccountAsAdmin($admin, $author);

    $note->refresh();
    expect($note->author_id)->toBeNull()
        ->and($note->body)->toBe('authored note survives')
        ->and(User::find($author->id))->toBeNull();
});

it('cascades notes ABOUT a member away when that member is deleted', function () {
    $admin = Users::inGroups(['admins']);
    $mod = Users::inGroups(['moderators']);
    $subject = Users::inGroups(['members', 'tl1']);
    StaffNote::create(['user_id' => $subject->id, 'author_id' => $mod->id, 'body' => 'about the subject']);

    $this->actingAs($admin);
    app(AccountDeletionService::class)->deleteAccountAsAdmin($admin, $subject);

    expect(StaffNote::where('user_id', $subject->id)->count())->toBe(0);
});
