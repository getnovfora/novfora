<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Admin;

use App\Models\User;
use App\Permissions\Scope;
use App\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The single definition of the ACP's Invision-style information architecture (ACP v3 · v3-h, foundations §3):
 * an icon RAIL of top-level sections → a per-section SIDEBAR of sub-page clusters → a per-section dashboard.
 * The rail, the section sidebar, the section landings, and the client-side quick-search all build from this, so
 * the navigation can never drift from the routes.
 *
 * Every sub-page is guarded by Route::has(): an item only appears once its route is registered, which lets the
 * panel be assembled slice-by-slice without the nav ever referencing a route that doesn't exist yet, and lets
 * the authz-walk test iterate exactly the routes that are live. Labels are i18n keys under the single `admin.*`
 * group (G7/G8). Counts/badges live on the dashboards, not here, so the chrome stays O(1) on every admin page.
 */
final class AdminNavigation
{
    /**
     * The ordered rail. Each section: [icon, landing-route, clusters]. A cluster is [heading|null, items];
     * an item is [labelKey, route, icon, external?]. `external` items link out of the ACP (e.g. the MCP).
     *
     * @var array<string, array{0:string,1:string,2:list<array{0:?string,1:list<array{0:string,1:string,2:string,3?:bool}>}>}>
     */
    private const SECTIONS = [
        'overview' => ['gauge', 'admin.dashboard', [
            [null, [
                ['dashboard', 'admin.dashboard', 'gauge'],
                ['analytics', 'admin.analytics', 'chart'],
            ]],
        ]],
        'forums' => ['folder', 'admin.forums', [
            [null, [
                ['structure', 'admin.structure', 'folder'],
                ['prefixes', 'admin.prefixes', 'pin'],
            ]],
        ]],
        'members' => ['users', 'admin.members', [
            [null, [
                ['all_members', 'admin.members.index', 'users'],
                ['directory', 'admin.members.directory', 'users'],
                ['staff_flair', 'admin.members.staff-flair', 'shield'], // v3-g: staff flair + Team roster (display-only)
                ['profile_fields', 'admin.members.profile-fields', 'list'],
                ['badges', 'admin.badges', 'check-circle'],
                ['tiers', 'admin.tiers', 'check-circle'],
                ['memberships', 'admin.memberships', 'check-circle'],
            ]],
        ]],
        'groups' => ['grid', 'admin.groups', [
            [null, [
                ['groups', 'admin.members.groups', 'grid'],
                ['group_permissions', 'admin.groups.permissions', 'shield'],
                ['roles', 'admin.groups.roles', 'shield'],
                ['join_requests', 'admin.groups.requests', 'inbox'],
            ]],
        ]],
        'moderation' => ['flag', 'admin.moderation', [
            [null, [
                ['approval_queue', 'moderation.queue', 'check-circle', true],
                ['reports', 'moderation.reports', 'flag', true],
                ['mod_panel', 'moderation.dashboard', 'shield', true],
                ['spam_intelligence', 'admin.spam-intelligence', 'shield'],
                ['warning_types', 'admin.moderation.warning-types', 'flag'], // v4 A3: warning-type CRUD + thresholds
                ['canned_replies', 'admin.moderation.canned-replies', 'message'], // T1: stock moderator replies
                ['moderators', 'admin.moderators', 'users'], // v3-b: per-forum moderator assignments overview
                ['moderation_settings', 'admin.settings.moderation', 'cog'],
            ]],
        ]],
        'appearance' => ['palette', 'admin.appearance', [
            [null, [
                ['appearance', 'admin.settings.appearance', 'palette'],
                ['themes', 'admin.settings.themes', 'palette'],
                ['templates', 'admin.settings.templates', 'pencil'],
                ['layout', 'admin.layout', 'sliders'],
            ]],
        ]],
        'plugins' => ['plug', 'admin.plugins', [
            [null, [
                ['modules', 'admin.modules', 'plug'],
                ['webhooks', 'admin.webhooks', 'mail'],
            ]],
        ]],
        'analytics' => ['chart', 'admin.analytics', [
            [null, [
                ['analytics', 'admin.analytics', 'chart'],
            ]],
        ]],
        'settings' => ['sliders', 'admin.settings', [
            [null, [
                ['general', 'admin.settings.general', 'cog'],
                ['registration', 'admin.settings.registration', 'user'],
                ['email', 'admin.settings.email', 'mail'],
                ['antispam', 'admin.settings.antispam', 'lock'],
                ['clubs', 'admin.settings.clubs', 'users'],
                ['sso', 'admin.settings.sso', 'lock'],
                ['search', 'admin.settings.search', 'database'],
                ['payments', 'admin.settings.payments', 'check-circle'],
            ]],
        ]],
        'system' => ['database', 'admin.system', [
            [null, [
                ['service_tier', 'admin.system.tier', 'database'],
                ['backups', 'admin.system.backups', 'inbox'],
                ['upgrade', 'admin.system.upgrade', 'arrow-up'],
                ['suppressions', 'admin.system.suppressions', 'mail'],
                ['audit', 'admin.system.audit', 'list'],
                ['tasks', 'admin.system.tasks', 'clock'],
            ]],
        ]],
        'security' => ['shield', 'admin.security', [
            [null, [
                ['co_owners', 'admin.security.co-owners', 'shield'], // v3-a: co-owner tier
                ['admin_accounts', 'admin.security.accounts', 'users'], // v3-a: Admin Manager (restricted admins)
                ['permissions', 'admin.security.permissions', 'shield'],
                ['active_delegations', 'admin.security.delegations', 'clock'], // v3-f: temporary-access delegation
            ]],
        ]],
    ];

    /** Map a settings-registry `group` to its page route + section (for the search index grouping). */
    private const SETTINGS_GROUPS = [
        'general' => ['admin.settings.general', 'general'],
        'registration' => ['admin.settings.registration', 'registration'],
        'email' => ['admin.settings.email', 'email'],
        'moderation' => ['admin.settings.moderation', 'moderation_settings'],
        'antispam' => ['admin.settings.antispam', 'antispam'],
        'appearance' => ['admin.settings.appearance', 'appearance'],
        'members' => ['admin.members.directory', 'directory'],
        'search' => ['admin.settings.search', 'search'],
        'payments' => ['admin.settings.payments', 'payments'],
    ];

    /**
     * The icon rail: every section whose landing route is registered, in order, with its resolved URL and an
     * `active` flag for the section the current request lives in.
     *
     * @return list<array{key:string,label:string,icon:string,route:string,url:string,active:bool}>
     */
    public static function rail(): array
    {
        $active = self::activeSectionKey();
        $user = auth()->user();
        $rail = [];

        foreach (self::SECTIONS as $key => [$icon, $landing]) {
            if (! Route::has($landing) || ! self::canAccessSection($user, $key)) {
                continue;
            }
            $rail[] = [
                'key' => $key,
                'label' => (string) __("admin.sections.$key"),
                'icon' => $icon,
                'route' => $landing,
                'url' => route($landing),
                'active' => $key === $active,
            ];
        }

        return $rail;
    }

    /**
     * The sub-page clusters for one section (the section sidebar). Each item is resolved + Route::has()-gated.
     *
     * @return list<array{heading:?string,items:list<array{label:string,route:string,url:string,icon:string,active:bool,external:bool}>}>
     */
    public static function sidebar(?string $sectionKey = null): array
    {
        $sectionKey ??= self::activeSectionKey();
        $def = self::SECTIONS[$sectionKey] ?? null;
        if ($def === null || ! self::canAccessSection(auth()->user(), $sectionKey)) {
            return [];
        }

        $clusters = [];
        foreach ($def[2] as $cluster) {
            // Forward-looking: a cluster may carry a translation-key heading once a section has several labelled
            // clusters; today every section is a single unnamed cluster (the @var keeps that an open shape).
            /** @var ?string $heading */
            $heading = $cluster[0];
            $live = [];
            foreach ($cluster[1] as $item) {
                $route = $item[1];
                if (! Route::has($route)) {
                    continue;
                }
                $live[] = [
                    'label' => (string) __('admin.nav.'.$item[0]),
                    'route' => $route,
                    'url' => route($route),
                    'icon' => $item[2],
                    'active' => request()->routeIs($route),
                    'external' => (bool) ($item[3] ?? false),
                ];
            }
            if ($live !== []) {
                $clusters[] = ['heading' => $heading !== null ? (string) __($heading) : null, 'items' => $live];
            }
        }

        return $clusters;
    }

    /** The label of a section (for breadcrumbs / the section dashboard heading). */
    public static function sectionLabel(string $key): string
    {
        return (string) __("admin.sections.$key");
    }

    /**
     * Whether $user may see a rail SECTION (ACP v3 · v3-a, ADR-0080). 'overview' is the any-admin home — anyone
     * who passed the admin.access gate. Every other section gates on admin.<section>.access: a full admin holds
     * all nine non-security keys via the administrator preset, a bundle-restricted admin only their granted
     * subset, and admin.security.access is co-owner-only. The rail, sidebar, and search all honour this, and
     * SectionController re-checks it for direct URL loads.
     */
    public static function canAccessSection(?User $user, string $key): bool
    {
        if ($key === 'overview') {
            return true;
        }

        return $user instanceof User && $user->canDo("admin.{$key}.access", Scope::global());
    }

    /**
     * Which rail section the current request belongs to — the section that owns the active route name. Defaults
     * to 'overview' (the implicit any-admin home). Drives the rail highlight + which sidebar the shell renders.
     */
    public static function activeSectionKey(): string
    {
        foreach (self::routeToSection() as $routeName => $sectionKey) {
            if (request()->routeIs($routeName)) {
                return $sectionKey;
            }
        }

        return 'overview';
    }

    /**
     * Flat route-name → section-key map, derived from the section definitions (landing routes + every sub-page).
     * A route appearing in more than one section (e.g. analytics under Overview and Analytics) binds to the
     * LAST section that lists it, so its own section wins the highlight.
     *
     * @return array<string,string>
     */
    private static function routeToSection(): array
    {
        $map = [];
        foreach (self::SECTIONS as $key => [, $landing, $clusters]) {
            $map[$landing] = $key;
            foreach ($clusters as [, $items]) {
                foreach ($items as $item) {
                    if (empty($item[3] ?? false)) { // external links don't claim the section highlight
                        $map[$item[1]] = $key;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * The flat search index: every reachable admin page plus every settings field LABEL, each pointing at a page
     * URL (settings fields jump to their anchor). Each entry is tagged `type` (page|setting) so the server-side
     * ACP search can split them into result groups; the Alpine quick-filter ignores `type` and matches on
     * label+group. Members are searched server-side (SearchController) since they aren't a fixed set.
     *
     * @return list<array{label:string,group:string,url:string,type:string}>
     */
    public static function searchIndex(): array
    {
        $index = [];
        $user = auth()->user();
        $sectionOf = self::routeToSection(); // route name → owning section (for the settings-field gate below)

        // Every reachable nav page the viewer may open (skip external links — they leave the ACP; skip a section
        // the viewer can't access so a restricted admin never sees a result that would 403 — v3-a, ADR-0080).
        foreach (self::SECTIONS as $sectionKey => [, , $clusters]) {
            if (! self::canAccessSection($user, $sectionKey)) {
                continue;
            }
            foreach ($clusters as [, $items]) {
                foreach ($items as $item) {
                    if (! empty($item[3] ?? false) || ! Route::has($item[1])) {
                        continue;
                    }
                    $index[] = [
                        'label' => (string) __('admin.nav.'.$item[0]),
                        'group' => (string) __("admin.sections.$sectionKey"),
                        'url' => route($item[1]),
                        'type' => 'page',
                    ];
                }
            }
        }

        // Every settings field label → its page + anchor (the SMF/Invision "jump to a setting" ergonomic), gated
        // by the access of the section that owns the target page.
        foreach (SettingsRegistry::all() as $key => $def) {
            $target = self::SETTINGS_GROUPS[$def->group] ?? null;
            if ($target === null || ! Route::has($target[0]) || ! self::canAccessSection($user, $sectionOf[$target[0]] ?? '')) {
                continue;
            }
            $index[] = [
                'label' => $def->label,
                'group' => (string) __('admin.nav.'.$target[1]),
                'url' => route($target[0]).'#setting-'.Str::slug($key, '-'),
                'type' => 'setting',
            ];
        }

        return $index;
    }
}
