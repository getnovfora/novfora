<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\BadgeManager;
use App\Models\Badge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

// ── Access control ─────────────────────────────────────────────────────────────────────────────────────

it('blocks a signed-in member from the badges component (403)', function () {
    $member = Users::inGroups(['members']);
    $this->actingAs($member);

    Livewire::test('admin.badges')->assertStatus(403);
});

it('blocks a moderator without badge.manage / admin.access from the badges component (403)', function () {
    // Moderators have topic.moderate etc. but NOT admin.access — the ACP gate rejects them.
    $mod = Users::inGroups(['moderators']);
    $this->actingAs($mod);

    Livewire::test('admin.badges')->assertStatus(403);
});

// ── Render ─────────────────────────────────────────────────────────────────────────────────────────────

it('renders the badges page for a 2FA admin', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    $this->actingAs($admin)
        ->get(route('admin.badges'))
        ->assertOk()
        ->assertSee('Badges');
});

// ── Create ─────────────────────────────────────────────────────────────────────────────────────────────

it('a 2FA admin can create a badge through the SFC and the criteria are normalised', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    Livewire::test('admin.badges')
        ->call('newBadge')
        ->set('name', 'Veteran Poster')
        ->set('criteriaType', 'post_count')
        ->set('threshold', 100)
        ->set('colorToken', 'blue')
        ->call('save')
        ->assertHasNoErrors();

    $badge = Badge::where('name', 'Veteran Poster')->first();
    expect($badge)->not->toBeNull()
        ->and($badge->slug)->toBe('veteran-poster')
        ->and($badge->criteria)->toBe(['type' => 'post_count', 'threshold' => 100])
        ->and($badge->color_token)->toBe('blue')
        ->and($badge->is_active)->toBeTrue();
});

it('a join badge is created with no threshold in the criteria document', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    Livewire::test('admin.badges')
        ->call('newBadge')
        ->set('name', 'Welcome')
        ->set('criteriaType', 'join')
        ->call('save')
        ->assertHasNoErrors();

    $badge = Badge::where('name', 'Welcome')->first();
    expect($badge)->not->toBeNull()
        ->and($badge->criteria)->toBe(['type' => 'join'])
        ->and(array_key_exists('threshold', $badge->criteria))->toBeFalse();
});

// ── Edit ───────────────────────────────────────────────────────────────────────────────────────────────

it('editing a badge updates name and criteria but slug is unchanged', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    $badge = Badge::factory()->criteria(['type' => 'post_count', 'threshold' => 10])->create([
        'name' => 'Early Bird',
        'slug' => 'early-bird',
    ]);

    Livewire::test('admin.badges')
        ->call('edit', $badge->id)
        ->set('name', 'First Steps')
        ->set('criteriaType', 'post_count')
        ->set('threshold', 25)
        ->call('save')
        ->assertHasNoErrors();

    $badge->refresh();
    expect($badge->name)->toBe('First Steps')
        ->and($badge->slug)->toBe('early-bird')   // slug is the stable identity — never changes
        ->and($badge->criteria)->toBe(['type' => 'post_count', 'threshold' => 25]);
});

// ── Delete ─────────────────────────────────────────────────────────────────────────────────────────────

it('deleting a badge removes the badge and its user_badges rows', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    $badge = Badge::factory()->create();
    $member = Users::inGroups(['members']);

    // Seed an award row so we can verify the cascade delete.
    DB::table('user_badges')->insert([
        'user_id' => $member->id,
        'badge_id' => $badge->id,
        'awarded_at' => now(),
    ]);

    expect(DB::table('user_badges')->where('badge_id', $badge->id)->count())->toBe(1);

    Livewire::test('admin.badges')
        ->call('askDelete', $badge->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect(Badge::find($badge->id))->toBeNull()
        ->and(DB::table('user_badges')->where('badge_id', $badge->id)->count())->toBe(0);
});

// ── Validation ─────────────────────────────────────────────────────────────────────────────────────────

it('rejects invalid criteria (threshold 0) with a validation error and writes nothing', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    $countBefore = Badge::count();

    Livewire::test('admin.badges')
        ->call('newBadge')
        ->set('name', 'Bad Badge')
        ->set('criteriaType', 'post_count')
        ->set('threshold', 0)   // below the minimum of 1
        ->call('save')
        ->assertHasErrors(['threshold']);

    // No badge persisted.
    expect(Badge::count())->toBe($countBefore);
});

it('rejects a missing name with a validation error and writes no new row', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    $countBefore = Badge::count();

    Livewire::test('admin.badges')
        ->call('newBadge')
        ->set('name', '')
        ->set('criteriaType', 'post_count')
        ->set('threshold', 10)
        ->call('save')
        ->assertHasErrors(['name']);

    // Count must not have grown — no badge was created despite the attempted save.
    expect(Badge::count())->toBe($countBefore);
});

// ── Slug collision ─────────────────────────────────────────────────────────────────────────────────────

it('slug collision on create gets a -2 suffix', function () {
    // Pre-create a badge occupying the target slug.
    Badge::factory()->create(['name' => 'Top Contributor', 'slug' => 'top-contributor']);

    $manager = app(BadgeManager::class);
    $second = $manager->create([
        'name' => 'Top Contributor',
        'criteria' => ['type' => 'post_count', 'threshold' => 50],
    ]);

    expect($second->slug)->toBe('top-contributor-2');

    // A third collision gets -3.
    $third = $manager->create([
        'name' => 'Top Contributor',
        'criteria' => ['type' => 'post_count', 'threshold' => 200],
    ]);

    expect($third->slug)->toBe('top-contributor-3');
});
