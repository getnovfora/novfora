<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\User;
use App\Settings\SettingsRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| ACP v3 · v3-h — the Invision-style information architecture: the icon rail of sections, per-section dashboard
| landings, the old→new 301 route moves, and the global ACP search (pages + settings + members).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** The rail sections that have a generic dashboard landing (Overview = dashboard, Analytics = its own page). */
function acpSectionLandings(): array
{
    return [
        'admin.forums' => 'Forums',
        'admin.members' => 'Members',
        'admin.groups' => 'Groups',
        'admin.moderation' => 'Moderation',
        'admin.appearance' => 'Appearance',
        'admin.plugins' => 'Plugins',
        'admin.settings' => 'Settings',
        'admin.system' => 'System',
        'admin.security' => 'Security',
    ];
}

/** OLD admin URL → its new section home (must 301). Mirrors the redirect map in routes/web.php. */
function acpRouteMoves(): array
{
    return [
        '/admin/structure' => '/admin/forums/structure',
        '/admin/prefixes' => '/admin/forums/prefixes',
        '/admin/members/groups' => '/admin/groups/manage',
        '/admin/badges' => '/admin/members/badges',
        '/admin/tiers' => '/admin/members/tiers',
        '/admin/memberships' => '/admin/members/memberships',
        '/admin/spam-intelligence' => '/admin/moderation/spam-intelligence',
        '/admin/settings/moderation' => '/admin/moderation/settings',
        '/admin/settings/appearance' => '/admin/appearance/settings',
        '/admin/settings/themes' => '/admin/appearance/themes',
        '/admin/settings/templates' => '/admin/appearance/templates',
        '/admin/layout' => '/admin/appearance/layout',
        '/admin/modules' => '/admin/plugins/modules',
        '/admin/webhooks' => '/admin/plugins/webhooks',
        '/admin/system/permissions' => '/admin/security/permissions',
    ];
}

describe('per-section dashboard landings', function () {
    it('renders every section landing (200) with its title for a 2FA admin', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));

        foreach (acpSectionLandings() as $route => $title) {
            $this->actingAs($admin)->get(route($route))
                ->assertOk("section landing {$route} should render 200")
                ->assertSee($title);
        }
    });

    it('forbids a non-admin from every section landing (403)', function () {
        $member = Users::inGroups(['members']);

        foreach (array_keys(acpSectionLandings()) as $route) {
            $this->actingAs($member)->get(route($route))->assertForbidden("expected 403 on {$route}");
        }
    });
});

describe('the icon rail', function () {
    it('renders the full section set on an admin page', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));

        $res = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        // The rail landmark + a link to every section's landing (Overview/Analytics reuse existing pages).
        $res->assertSee(__('admin.sections_label'));
        foreach (['admin.dashboard', 'admin.analytics', ...array_keys(acpSectionLandings())] as $route) {
            $res->assertSee(route($route), false);
        }
        // Every section label appears in the rail.
        foreach (array_values(acpSectionLandings()) as $label) {
            $res->assertSee($label);
        }
    });
});

describe('old → new 301 route moves', function () {
    it('301s every old admin URL to its new section home', function () {
        foreach (acpRouteMoves() as $old => $new) {
            $res = $this->get($old);
            $res->assertStatus(301);
            expect($res->headers->get('Location'))->toContain($new);
        }
    });

    it('keeps the route NAMES stable so call-sites resolve to the new URLs', function () {
        expect(route('admin.structure', absolute: false))->toBe('/admin/forums/structure');
        expect(route('admin.members.groups', absolute: false))->toBe('/admin/groups/manage');
        expect(route('admin.security.permissions', absolute: false))->toBe('/admin/security/permissions');
    });
});

describe('the global ACP search', function () {
    it('returns matching admin pages', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));

        $this->actingAs($admin)->get(route('admin.search', ['q' => 'Audit']))
            ->assertOk()
            ->assertSee(__('admin.search.group_pages'))
            ->assertSee('Audit log');
    });

    it('returns matching members', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));
        User::factory()->create(['username' => 'zzsearchablemember']);

        $this->actingAs($admin)->get(route('admin.search', ['q' => 'zzsearchablemember']))
            ->assertOk()
            ->assertSee(__('admin.search.group_members'))
            ->assertSee('zzsearchablemember');
    });

    it('returns matching settings fields', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));

        // Pull a real settings field from a search-indexed group, so the assertion never guesses a label.
        $indexed = ['general', 'registration', 'email', 'moderation', 'antispam', 'appearance', 'members', 'search', 'payments'];
        $field = collect(SettingsRegistry::all())->first(fn ($def) => in_array($def->group, $indexed, true));
        expect($field)->not->toBeNull();

        $this->actingAs($admin)->get(route('admin.search', ['q' => $field->label]))
            ->assertOk()
            ->assertSee(__('admin.search.group_settings'));
    });

    it('prompts (no groups) on an empty query', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));

        $this->actingAs($admin)->get(route('admin.search'))
            ->assertOk()
            ->assertSee(__('admin.search.prompt'));
    });

    it('forbids a non-admin from the ACP search (403)', function () {
        $member = Users::inGroups(['members']);
        $this->actingAs($member)->get(route('admin.search', ['q' => 'x']))->assertForbidden();
    });
});
