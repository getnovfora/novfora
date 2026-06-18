<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
| ACP v3 (v3-h) admin control-panel strings. ONE `admin.*` group for the whole panel (foundations §7 / G7):
| the Invision-style icon rail, the per-section sidebars, the section dashboards, and the ACP search.
|
| G8 (ADR-0079): this group is named `admin` only because a repo-wide check confirmed NO bare `__('Admin')`
| string-key caller exists — on the case-insensitive bind-mount, such a caller would load THIS array and 500
| on htmlspecialchars(array). NEVER add a bare `__('Admin')`; always use `admin.<key>`. Likewise the section
| labels live UNDER this group (`admin.sections.forums`), never as standalone `forums.php`/`members.php` files
| (`__('Forums')`/`__('Members')` are live string-keys — see common.php).
*/

return [
    // Shell chrome.
    'title' => 'Admin',
    'menu' => 'Menu',
    'open_menu' => 'Open admin menu',
    'back_to_forum' => 'Back to forum',
    'sections_label' => 'Admin sections',
    'section_nav_label' => 'Section pages',

    // The icon-rail sections (foundations §3). Keyed by section slug.
    'sections' => [
        'overview' => 'Overview',
        'forums' => 'Forums',
        'members' => 'Members',
        'groups' => 'Groups',
        'moderation' => 'Moderation',
        'appearance' => 'Appearance',
        'plugins' => 'Plugins',
        'analytics' => 'Analytics',
        'settings' => 'Settings',
        'system' => 'System',
        'security' => 'Security',
    ],

    // Sub-page (section-sidebar) labels.
    'nav' => [
        'dashboard' => 'Dashboard',
        'overview' => 'Overview',
        'analytics' => 'Analytics',
        'structure' => 'Forums & structure',
        'prefixes' => 'Prefixes',
        'groups' => 'Groups',
        'group_permissions' => 'Group permissions',
        'roles' => 'Roles',
        'join_requests' => 'Join requests',
        'directory' => 'Directory',
        'profile_fields' => 'Profile fields',
        'badges' => 'Badges',
        'tiers' => 'Membership tiers',
        'memberships' => 'Memberships',
        'approval_queue' => 'Approval queue',
        'reports' => 'Reports',
        'mod_panel' => 'Mod control panel',
        'spam_intelligence' => 'Spam intelligence',
        'moderation_settings' => 'Moderation settings',
        'appearance' => 'Appearance',
        'themes' => 'Themes',
        'templates' => 'Templates',
        'layout' => 'Layout & widgets',
        'modules' => 'Modules & plugins',
        'webhooks' => 'Webhooks',
        'general' => 'General',
        'registration' => 'Registration',
        'email' => 'Email',
        'antispam' => 'Anti-spam',
        'clubs' => 'Clubs',
        'sso' => 'Social login',
        'search' => 'Search',
        'payments' => 'Payments',
        'service_tier' => 'Service tier',
        'backups' => 'Backups & restore',
        'upgrade' => 'Upgrade',
        'suppressions' => 'Email suppressions',
        'audit' => 'Audit log',
        'tasks' => 'Scheduled tasks',
        'permissions' => 'Permission Inspector',
    ],

    // Per-section dashboard landing copy (foundations §3: start with the section summary; widgets land later).
    'landing' => [
        'forums' => ['title' => 'Forums', 'intro' => 'Shape the board: the forum tree, topic prefixes, and tags.'],
        'members' => ['title' => 'Members', 'intro' => 'Find and manage members, profile fields, badges, and membership tiers.'],
        'groups' => ['title' => 'Groups', 'intro' => 'Member groups, custom roles, auto-promotion, and group permissions.'],
        'moderation' => ['title' => 'Moderation', 'intro' => 'Queues, reports, spam intelligence, and moderation policy.'],
        'appearance' => ['title' => 'Appearance', 'intro' => 'Themes, templates, layout, and the board’s look and feel.'],
        'plugins' => ['title' => 'Plugins', 'intro' => 'Modules, plugins, and outbound webhooks.'],
        'settings' => ['title' => 'Settings', 'intro' => 'Board configuration: registration, email, anti-spam, clubs, and more.'],
        'system' => ['title' => 'System', 'intro' => 'Service tier, backups, upgrades, scheduled tasks, and the audit log.'],
        'security' => ['title' => 'Security', 'intro' => 'Permission inspection, admin accounts, and the security audit trail.'],
    ],
    'landing_jump' => 'Open a page in this section:',
    'landing_empty' => 'No pages are available in this section yet.',

    // The custom role builder (v3-d).
    'roles' => [
        'title' => 'Roles',
    ],

    // The card-per-group permission editor (v3-c).
    'perms' => [
        'title' => 'Group permissions',
        'intro_global' => 'Set the GLOBAL default for each group. Per-forum and club screens override these.',
        'intro_forum' => 'Override the global defaults for this forum. “No” falls back to the global default.',
        'intro_club' => 'Set permissions for this club. “No” falls back to the global default.',
        'state' => [
            'yes' => 'Yes',
            'no' => 'No',
            'never' => 'Never',
        ],
        'state_help' => [
            'yes' => 'Allowed (grants the permission)',
            'no' => 'Not set — inherits from the parent scope',
            'never' => 'Never — a hard deny no allow can override',
        ],
        'inherits' => 'inherits',
        'locked_rank' => 'This group outranks you — you cannot edit it.',
        'locked_recovery' => 'You cannot remove the administrators group’s own admin access — it would lock everyone out.',
        'saved' => 'Saved.',
        'bulk_apply' => 'Apply to every forum in this category',
        'bulk_help' => 'Copy this forum’s group permissions onto every other forum under its category.',
        'bulk_done' => ':count forum(s) updated.',
        'no_category' => 'This forum is not inside a category, so there is nothing to apply to.',
        'empty_groups' => 'No groups to show.',
    ],

    // The ACP search (pages · settings · members).
    'search' => [
        'label' => 'Search admin pages, settings, and members',
        'placeholder' => 'Search admin…',
        'no_matches' => 'No matches.',
        'heading' => 'Admin search',
        'results_for' => 'Results for “:q”',
        'empty' => 'Nothing matched “:q”.',
        'prompt' => 'Type a query to search admin pages, settings, and members.',
        'group_pages' => 'Pages',
        'group_settings' => 'Settings',
        'group_members' => 'Members',
        'open' => 'Open',
        'view_member' => 'View member',
    ],
];
