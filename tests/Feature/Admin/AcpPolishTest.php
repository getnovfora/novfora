<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Support\Users;

/*
| ACP feel pass (Pillar 2, Slice 4): the persistent-shell wire:navigate, section-landing breadcrumbs, a more
| prominent search affordance ("/" focus), and the x-admin.form-section rhythm. Server-render
| assertions; the live SPA-nav smoothness itself is browser-verified (Dusk/CI). The full AdminAccessWalkTest
| separately proves every ACP page still renders (no regression from these shell edits).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('x-admin.form-section renders heading + help + a divider; `first` drops the divider', function () {
    $html = Blade::render('<x-admin.form-section heading="Identity" help="How the site presents itself." id="s-id">BODY</x-admin.form-section>');

    expect($html)
        ->toContain('Identity')
        ->toContain('How the site presents itself.')
        ->toContain('id="s-id"')
        ->toContain('border-t border-line')
        ->toContain('BODY');

    expect(Blade::render('<x-admin.form-section first heading="Top">BODY</x-admin.form-section>'))
        ->not->toContain('border-t border-line');
});

it('the settings/general page adopts the form-section rhythm while keeping its search anchors', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    $this->actingAs($admin)
        ->get(route('admin.settings.general'))
        ->assertOk()
        ->assertSee('Identity')                            // form-section heading
        ->assertSee('Availability')
        ->assertSee('id="setting-general-site-name"', false); // search-jump anchor preserved
});

it('the ACP rail uses wire:navigate for the persistent-shell nav', function () {
    $sections = [['key' => 'forums', 'label' => 'Forums', 'icon' => 'folder', 'url' => '/admin/forums', 'active' => false]];

    expect(Blade::render('<x-admin.rail :sections="$sections" />', compact('sections')))
        ->toContain('wire:navigate');
});

it('the section sidebar adds a "/" search shortcut and wire:navigate links', function () {
    $clusters = [['heading' => 'Manage', 'items' => [
        ['label' => 'Structure', 'icon' => 'folder', 'url' => '/admin/structure', 'active' => true, 'external' => false],
    ]]];

    $html = Blade::render('<x-admin.nav :clusters="$clusters" :search-index="[]" search-url="/admin/search" />', compact('clusters'));

    expect($html)
        ->toContain('wire:navigate')        // sidebar links morph the shell instead of full-reloading
        ->toContain('focusOnSlash')         // the "/" key focuses the search
        ->not->toContain('novfora.acp.recents'); // the Recent shelf was removed (no localStorage recents)
});

it('section landings now carry a breadcrumb trail and wire:navigate cards', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    $this->actingAs($admin)
        ->get(route('admin.forums'))
        ->assertOk()
        ->assertSee('aria-label="Breadcrumb"', false) // section landing now has a breadcrumb (was missing)
        ->assertSee('wire:navigate', false);          // rail + section cards morph the shell
});
