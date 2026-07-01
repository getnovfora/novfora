<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\AdminBundleService;
use App\Models\Group;
use App\Models\NavigationItem;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('seeds and renders the shipped public navigation defaults by audience', function () {
    expect(NavigationItem::query()->count())->toBe(6);

    $guestHtml = $this->get(route('forums.index'))->assertOk()->getContent();
    expect($guestHtml)
        ->toContain('Forums')
        ->toContain('Clubs')
        ->toContain('Trending')
        ->toContain('Members')
        ->not->toContain('href="'.route('whats-new').'"');

    $member = Users::inGroups(['members']);
    $memberHtml = $this->actingAs($member)->get(route('forums.index'))->assertOk()->getContent();
    expect($memberHtml)->toContain('href="'.route('whats-new').'"');
});

it('honors selected-group visibility for custom navigation items', function () {
    NavigationItem::create([
        'title' => 'Staff docs',
        'link_type' => 'url',
        'url' => '/staff-docs',
        'position' => 99,
        'visibility' => 'groups',
        'group_ids' => [(int) Group::query()->where('slug', 'admins')->value('id')],
        'is_enabled' => true,
        'show_on_desktop' => true,
        'show_on_mobile' => true,
    ]);

    expect($this->get(route('forums.index'))->assertOk()->getContent())->not->toContain('Staff docs');

    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    expect($this->actingAs($admin)->get(route('forums.index'))->assertOk()->getContent())->toContain('Staff docs');
});

it('lets an appearance admin add, edit, reorder, and disable navigation items', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    $this->get(route('admin.navigation'))->assertOk()->assertSee('Navigation');

    Livewire::test('admin.navigation')
        ->set('form.title', 'Docs')
        ->set('form.link_type', 'url')
        ->set('form.url', '/docs')
        ->set('form.icon', 'globe')
        ->call('save')
        ->assertHasNoErrors();

    $item = NavigationItem::query()->where('title', 'Docs')->firstOrFail();
    expect($item->url)->toBe('/docs')
        ->and($item->icon)->toBe('globe');

    Livewire::test('admin.navigation')
        ->call('edit', $item->id)
        ->set('form.title', 'Docs & help')
        ->set('form.opens_new_tab', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($item->fresh()->title)->toBe('Docs & help')
        ->and($item->fresh()->opens_new_tab)->toBeTrue();

    $position = (int) $item->fresh()->position;
    Livewire::test('admin.navigation')->call('moveUp', $item->id);
    expect((int) $item->fresh()->position)->toBeLessThan($position);

    Livewire::test('admin.navigation')->call('toggle', $item->id);
    expect($item->fresh()->is_enabled)->toBeFalse();
});

it('rejects unsafe custom navigation URLs', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    Livewire::test('admin.navigation')
        ->set('form.title', 'Bad link')
        ->set('form.link_type', 'url')
        ->set('form.url', 'javascript:alert(1)')
        ->call('save')
        ->assertHasErrors(['form.url']);

    expect(NavigationItem::query()->where('title', 'Bad link')->exists())->toBeFalse();
});

it('blocks non-admins, admins without 2FA, and restricted admins without Appearance access', function () {
    $this->actingAs(Users::inGroups(['members']));
    Livewire::test('admin.navigation')->assertStatus(403);

    $this->actingAs(Users::inGroups(['admins']));
    Livewire::test('admin.navigation')->assertStatus(403);

    $owner = Users::inGroups(['admins']);
    $restricted = Users::withTwoFactor(Users::inGroups(['members']));
    app(AdminBundleService::class)->assign($owner, $restricted, Role::query()->where('slug', 'admin-bundle-community')->firstOrFail());

    $this->actingAs($restricted->fresh());
    Livewire::test('admin.navigation')->assertStatus(403);
    $this->get(route('admin.navigation'))->assertForbidden();
});
