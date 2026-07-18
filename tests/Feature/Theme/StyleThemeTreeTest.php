<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\SiteTheme;
use App\Models\User;
use App\Theme\StylePresets;
use App\Theme\StyleThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| U10 (NOV-108) — the multi-style tree: parent/child inheritance (child wins, depth-capped, cycle-safe),
| the per-user style chooser, versioned cache busting, and the shipped presets. Gate 4A's exit proof — a
| child theme built entirely in the ACP — lives at the bottom.
*/

uses(RefreshDatabase::class);

function treeManager(): StyleThemeManager
{
    return app(StyleThemeManager::class);
}

it('merges tokens through the parent chain with the child winning', function () {
    $m = treeManager();
    $root = $m->create(['name' => 'Root', 'tokens' => ['surface' => '#111111', 'ink' => '#222222'], 'custom_css' => '.root{color:red}']);
    $child = $m->create(['name' => 'Child', 'parent_id' => $root->id, 'tokens' => ['ink' => '#333333'], 'custom_css' => '.child{color:blue}']);

    $eff = $m->effective($child->refresh());
    expect($eff['tokens'])->toBe(['surface' => '#111111', 'ink' => '#333333'])
        ->and($eff['custom_css'])->toBe(".root{color:red}\n.child{color:blue}");
});

it('inherits accent, chrome, and dark tokens per-field from the nearest ancestor', function () {
    $m = treeManager();
    $root = $m->create(['name' => 'Root', 'accent_color' => '#123456', 'header_html' => '<p>banner</p>', 'tokens_dark' => ['surface' => '#000000']]);
    $child = $m->create(['name' => 'Child', 'parent_id' => $root->id]);

    $eff = $m->effective($child->refresh());
    expect($eff['accent_color'])->toBe('#123456')
        ->and($eff['header_html'])->toContain('banner')
        ->and($eff['tokens_dark'])->toBe(['surface' => '#000000']);
});

it('refuses a self-parent, a cycle, and an over-deep chain', function () {
    $m = treeManager();
    $a = $m->create(['name' => 'A']);
    $b = $m->create(['name' => 'B', 'parent_id' => $a->id]);

    expect(fn () => $m->update($a->refresh(), ['name' => 'A', 'parent_id' => $a->id]))
        ->toThrow(InvalidArgumentException::class, 'own parent');
    expect(fn () => $m->update($a->refresh(), ['name' => 'A', 'parent_id' => $b->id]))
        ->toThrow(InvalidArgumentException::class, 'cycle');

    // Build a chain at the cap: c1 <- c2 <- c3 <- c4 (depth 4), then a child of c4 is depth 5 → refused.
    $c1 = $m->create(['name' => 'C1']);
    $c2 = $m->create(['name' => 'C2', 'parent_id' => $c1->id]);
    $c3 = $m->create(['name' => 'C3', 'parent_id' => $c2->id]);
    $c4 = $m->create(['name' => 'C4', 'parent_id' => $c3->id]);
    $c5 = $m->create(['name' => 'C5', 'parent_id' => $c4->id]);
    expect(fn () => $m->create(['name' => 'C6', 'parent_id' => $c5->id]))
        ->toThrow(InvalidArgumentException::class, 'levels');
});

it('refuses to delete a style that has children, then allows it once they are gone', function () {
    $m = treeManager();
    $root = $m->create(['name' => 'Root']);
    $child = $m->create(['name' => 'Child', 'parent_id' => $root->id]);

    expect(fn () => $m->delete($root->refresh()))->toThrow(InvalidArgumentException::class, 'child styles');

    $m->delete($child->refresh());
    $m->delete($root->refresh());
    expect(SiteTheme::count())->toBe(0);
});

it('resolves a member\'s chosen selectable style over the site default', function () {
    $m = treeManager();
    $default = $m->create(['name' => 'Default', 'activate' => true]);
    $picked = $m->create(['name' => 'Picked', 'is_user_selectable' => true, 'tokens' => ['surface' => '#abcdef']]);

    $user = User::factory()->create();
    $user->style_theme_id = $picked->id;
    $user->save();

    expect(treeManager()->resolveFor($user->fresh())?->id)->toBe($picked->id)
        ->and(treeManager()->resolveFor(null)?->id)->toBe($default->id)
        ->and(treeManager()->css($user->fresh()))->toContain('--surface:#abcdef;');
});

it('falls back to the site default when the chosen style is not selectable or is deleted', function () {
    $m = treeManager();
    $default = $m->create(['name' => 'Default', 'activate' => true]);
    $private = $m->create(['name' => 'Private', 'is_user_selectable' => false]);

    $user = User::factory()->create();
    $user->style_theme_id = $private->id;
    $user->save();

    expect(treeManager()->resolveFor($user->fresh())?->id)->toBe($default->id);

    // Deleting a chosen style clears the selection column too.
    $selectable = $m->create(['name' => 'Gone soon', 'is_user_selectable' => true]);
    $user->style_theme_id = $selectable->id;
    $user->save();
    $m->delete($selectable);
    expect($user->fresh()->style_theme_id)->toBeNull();
});

it('serves a child\'s updated CSS after a PARENT edit (versioned cache busting)', function () {
    $m = treeManager();
    $root = $m->create(['name' => 'Root', 'tokens' => ['surface' => '#111111']]);
    $child = $m->create(['name' => 'Child', 'parent_id' => $root->id, 'activate' => true]);

    expect($m->css())->toContain('--surface:#111111;');

    $m->update($root->refresh(), ['name' => 'Root', 'tokens' => ['surface' => '#999999']]);
    expect(treeManager()->css())->toContain('--surface:#999999;')
        ->and(treeManager()->css())->not->toContain('--surface:#111111;');
});

it('installs the shipped presets idempotently and never activates one', function () {
    expect(StylePresets::install())->toBe(['novfora', 'novfora-daylight', 'novfora-midnight'])
        ->and(StylePresets::install())->toBe([]); // second run: nothing new

    $root = SiteTheme::where('slug', 'novfora')->firstOrFail();
    $day = SiteTheme::where('slug', 'novfora-daylight')->firstOrFail();
    $night = SiteTheme::where('slug', 'novfora-midnight')->firstOrFail();

    expect($day->parent_id)->toBe($root->id)
        ->and($night->parent_id)->toBe($root->id)
        ->and($day->is_user_selectable)->toBeTrue()
        ->and($night->is_user_selectable)->toBeTrue()
        ->and($root->is_user_selectable)->toBeFalse()
        ->and(SiteTheme::where('is_active', true)->count())->toBe(0);
});

it('lets a member pick a selectable style on the appearance page and clears it again', function () {
    $m = treeManager();
    $style = $m->create(['name' => 'Member pick', 'is_user_selectable' => true]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('settings.appearance.save'), ['style_theme_id' => $style->id])
        ->assertRedirect();
    expect($user->fresh()->style_theme_id)->toBe($style->id);

    $this->actingAs($user)
        ->post(route('settings.appearance.save'), ['style_theme_id' => ''])
        ->assertRedirect();
    expect($user->fresh()->style_theme_id)->toBeNull();
});

it('rejects picking a non-selectable style id (validation, not silent fallback)', function () {
    $m = treeManager();
    $private = $m->create(['name' => 'Staff only', 'is_user_selectable' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('settings.appearance.save'), ['style_theme_id' => $private->id])
        ->assertSessionHasErrors('style_theme_id');
    expect($user->fresh()->style_theme_id)->toBeNull();
});

it('renders the Style chooser card only when selectable styles exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.appearance'))->assertDontSee('Site default');

    treeManager()->create(['name' => 'Visible', 'is_user_selectable' => true]);
    $this->actingAs($user)->get(route('settings.appearance'))
        ->assertSee('Site default')
        ->assertSee('Visible');
});

// ── Gate 4A exit proof: a child theme built ENTIRELY in the ACP — create with parent, override two
// props, share it, and the served CSS shows the child override + the inherited parent values. ──
it('builds a child theme entirely through the ACP editor (Gate 4A)', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    // The seeded presets are present (DatabaseSeeder → StylePresetSeeder).
    $root = SiteTheme::where('slug', 'novfora')->firstOrFail();

    Livewire::test('admin.settings.themes')
        ->call('newTheme')
        ->set('name', 'My Community')
        ->set('parentId', (string) $root->id)
        ->set('userSelectable', true)
        ->set('tokens.surface', '#f0f4ff')
        ->set('tokensDark.surface', '#0a0f1e')
        ->call('save')
        ->assertHasNoErrors();

    $child = SiteTheme::where('name', 'My Community')->firstOrFail();
    expect($child->parent_id)->toBe($root->id)
        ->and($child->is_user_selectable)->toBeTrue();

    $m = treeManager();
    $m->activate($child);
    $css = $m->css();
    expect($css)->toContain('--surface:#f0f4ff;')
        ->and($css)->toContain(":root[data-theme='dark']{--surface:#0a0f1e;}");

    // A member can now pick it in the chooser.
    $member = User::factory()->create();
    $this->actingAs($member)
        ->post(route('settings.appearance.save'), ['style_theme_id' => $child->id])
        ->assertRedirect();
    expect($member->fresh()->style_theme_id)->toBe($child->id);
});
