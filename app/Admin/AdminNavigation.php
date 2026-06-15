<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Admin;

use App\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The single definition of the ACP's grouped left navigation (ACP v1, PART 1). Both the admin shell and
 * the client-side quick-search build from this, so the nav and the search index can never drift.
 *
 * Every item is guarded by Route::has(): an item only appears once its route is registered, which lets
 * the ACP be assembled part-by-part without the nav ever referencing a route that doesn't exist yet, and
 * lets the authz-walk test iterate exactly the routes that are live. Counts/badges deliberately live on
 * the dashboard, not here — the nav stays O(1) so it's free to render on every admin page.
 */
final class AdminNavigation
{
    /** Map a settings registry `group` to its page route + human group label (for the search index). */
    private const SETTINGS_GROUPS = [
        'general' => ['admin.settings.general', 'General'],
        'registration' => ['admin.settings.registration', 'Registration'],
        'email' => ['admin.settings.email', 'Email'],
        'moderation' => ['admin.settings.moderation', 'Moderation'],
        'antispam' => ['admin.settings.antispam', 'Anti-spam'],
        'appearance' => ['admin.settings.appearance', 'Appearance'],
        'members' => ['admin.members.directory', 'Members'],
        'search' => ['admin.settings.search', 'Search'],
    ];

    /**
     * The grouped nav. Each group: ['label','icon','items'=>[['label','route','icon','external'?]]].
     *
     * @return list<array{label:string,icon:string,items:list<array{label:string,route:string,url:string,icon:string,active:bool,external:bool}>}>
     */
    public static function groups(): array
    {
        $definition = [
            ['Overview', 'gauge', [
                ['Dashboard', 'admin.dashboard', 'gauge'],
                ['Analytics', 'admin.analytics', 'gauge'],
            ]],
            ['Settings', 'sliders', [
                ['General', 'admin.settings.general', 'cog'],
                ['Registration', 'admin.settings.registration', 'user'],
                ['Email', 'admin.settings.email', 'mail'],
                ['Moderation', 'admin.settings.moderation', 'shield'],
                ['Anti-spam', 'admin.settings.antispam', 'lock'],
                ['Appearance', 'admin.settings.appearance', 'palette'],
                ['Themes', 'admin.settings.themes', 'palette'],
                ['Templates', 'admin.settings.templates', 'pencil'],
                ['Clubs', 'admin.settings.clubs', 'users'],
                ['Social login', 'admin.settings.sso', 'lock'],
                ['Search', 'admin.settings.search', 'database'],
            ]],
            ['Members', 'users', [
                ['Groups', 'admin.members.groups', 'users'],
                ['Permissions', 'admin.system.permissions', 'shield'],
                ['Custom fields', 'admin.system.profile-fields', 'list'],
                ['Directory', 'admin.members.directory', 'users'],
                ['Membership tiers', 'admin.tiers', 'check-circle'],
                ['Memberships', 'admin.memberships', 'check-circle'],
            ]],
            ['Content', 'folder', [
                ['Forums & structure', 'admin.structure', 'folder'],
                ['Badges', 'admin.badges', 'check-circle'],
            ]],
            ['Extend', 'folder', [
                ['Modules & plugins', 'admin.modules', 'cog'],
                ['Layout & widgets', 'admin.layout', 'sliders'],
                ['Webhooks', 'admin.webhooks', 'mail'],
            ]],
            ['Moderation', 'flag', [
                ['Approval queue', 'moderation.queue', 'check-circle', true],
                ['Reports', 'moderation.reports', 'flag', true],
                ['Mod control panel', 'moderation.dashboard', 'shield', true],
            ]],
            ['System', 'database', [
                ['Service tier', 'admin.system.tier', 'database'],
                ['Backups & restore', 'admin.system.backups', 'inbox'],
                ['Upgrade', 'admin.system.upgrade', 'arrow-up'],
                ['Email suppressions', 'admin.system.suppressions', 'mail'],
                ['Audit log', 'admin.system.audit', 'list'],
                ['Tasks', 'admin.system.tasks', 'clock'],
            ]],
        ];

        $groups = [];
        foreach ($definition as [$label, $icon, $items]) {
            $live = [];
            foreach ($items as $item) {
                $route = $item[1];
                if (! Route::has($route)) {
                    continue;
                }
                $live[] = [
                    'label' => $item[0],
                    'route' => $route,
                    'url' => route($route),
                    'icon' => $item[2],
                    'active' => request()->routeIs($route),
                    'external' => (bool) ($item[3] ?? false),
                ];
            }
            if ($live !== []) {
                $groups[] = ['label' => $label, 'icon' => $icon, 'items' => $live];
            }
        }

        return $groups;
    }

    /**
     * The flat client-side search index: every admin page plus every settings field LABEL, each pointing
     * at a page URL (settings fields jump to their anchor). Pure data → JSON for the Alpine filter.
     *
     * @return list<array{label:string,group:string,url:string}>
     */
    public static function searchIndex(): array
    {
        $index = [];

        // Every reachable nav page.
        foreach (self::groups() as $group) {
            foreach ($group['items'] as $item) {
                $index[] = ['label' => $item['label'], 'group' => $group['label'], 'url' => $item['url']];
            }
        }

        // Every settings field label → its page + anchor (the SMF/Invision "jump to a setting" ergonomic).
        foreach (SettingsRegistry::all() as $key => $def) {
            $target = self::SETTINGS_GROUPS[$def->group] ?? null;
            if ($target === null || ! Route::has($target[0])) {
                continue;
            }
            $index[] = [
                'label' => $def->label,
                'group' => $target[1].' settings',
                'url' => route($target[0]).'#setting-'.Str::slug($key, '-'),
            ];
        }

        return $index;
    }
}
