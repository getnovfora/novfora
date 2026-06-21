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
        'staff_flair' => 'Staff flair',
        'profile_fields' => 'Profile fields',
        'badges' => 'Badges',
        'tiers' => 'Membership tiers',
        'memberships' => 'Memberships',
        'approval_queue' => 'Approval queue',
        'reports' => 'Reports',
        'mod_panel' => 'Mod control panel',
        'spam_intelligence' => 'Spam intelligence',
        'moderators' => 'Moderators',
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
        'co_owners' => 'Co-owners',
        'admin_accounts' => 'Admin Manager',
        'permissions' => 'Permission Inspector',
        'active_delegations' => 'Active delegations',
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

    // Per-forum moderator assignment (v3-b).
    'moderators' => [
        'title' => 'Forum moderators',
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

        // Simple-mode permissions (ADR-0089): the layman capability toggles + the Simple/Advanced switch.
        'mode_label' => 'Editor mode',
        'mode_simple' => 'Simple',
        'mode_advanced' => 'Advanced',
        'simple_intro' => 'Turn each capability on or off for a group. Switch to Advanced for the full Yes / No / Never controls.',
        'restricted_note' => 'Restricted by a trust-level or Never rule — manage in Advanced.',
        'capabilities' => [
            'read_reply' => ['label' => 'Read & reply', 'subtitle' => 'View forums and post replies'],
            'start_topics' => ['label' => 'Start new topics', 'subtitle' => 'Open new threads'],
            'post_media' => ['label' => 'Post links & images', 'subtitle' => 'Hyperlinks and embedded images (restricted for brand-new accounts)'],
            'react_vote' => ['label' => 'React & vote', 'subtitle' => 'React to posts and vote in polls'],
            'polls_tags' => ['label' => 'Create polls & tags', 'subtitle' => 'Attach polls; create and apply tags'],
            'follow' => ['label' => 'Follow members', 'subtitle' => 'Follow other members'],
            'pm' => ['label' => 'Send private messages', 'subtitle' => 'Start and reply to private conversations'],
        ],
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

    // ── The Permission Inspector's plain-language explanation layer (polish R3) ──────────────────────
    // A read-only presentation over the resolver trace: ONE faithful sentence per Decision::$reason, with
    // the user / permission label / scope name / holder name interpolated. The machine summary, the
    // technical detail card, and the candidate-entries table all stay BELOW it, unchanged, for power users.
    // The mapping mirrors PermissionResolver exactly: NEVER is absolute (no grant overrides it); a grant
    // decided below the global scope overrides the broader default; a ban pre-empts every ACL rule.
    // G8 (ADR-0079): always reference admin.inspector.*, never a bare key.
    'inspector' => [
        'heading' => 'In plain language',
        'technical_heading' => 'Technical resolution',
        'about_permission' => 'About this permission',
        'fact_permission' => 'Permission',
        'fact_decided_by' => 'Decided by',
        'fact_scope' => 'Where',

        'verdict' => [
            'allowed' => 'Allowed',
            'denied' => 'Denied',
        ],

        // One sentence per reason code (Decision::$reason). Placeholders: :user :permission :scope :holder.
        // :scope is a prepositional phrase ("site-wide" or "in the General Discussion forum"), so the
        // templates carry NO preposition before it — global reads as an adverb, named scopes as "in …".
        'reason' => [
            'user_allow' => ':user can :permission — a rule set directly on their account grants it :scope.',
            'group_allow' => ':user can :permission — :holder grants it :scope.',
            'never' => ':user is hard-denied :permission — a NEVER rule held by :holder :scope cannot be overridden by any grant.',
            'banned' => ':user is currently banned, so they cannot :permission — a ban (site-wide or scoped to this area) takes priority over every grant.',
            'default' => ':user cannot :permission — no rule grants it :scope or in any scope above it, so access is denied by default.',
        ],
        // Appended to a user_allow / group_allow sentence that was decided below the global scope.
        'override' => ' It overrides the broader site-wide default.',

        'scope' => [
            'global' => 'site-wide',             // facts-strip label AND the adverb used in the sentence
            'named' => 'the :name :level',        // facts-strip label, e.g. "the General Discussion forum"
            'unknown' => 'a :level (#:id)',        // an orphaned scope whose row is gone — never a raw "forum:2" code
            'in' => 'in :place',                  // wraps a named/unknown label into the sentence's :scope slot
            'level' => [
                'category' => 'category',
                'forum' => 'forum',
                'thread' => 'topic',
                'club' => 'club',
            ],
        ],

        'holder' => [
            'group_one' => 'the :name group',     // a single group grants / holds the NEVER
            'group_many' => 'the :names groups',   // several groups grant at the deciding scope
            'some_group' => 'a group',             // group_allow with no resolvable group row (defensive)
            'unknown_group' => 'a deleted group (#:id)',
            'unknown_user' => 'a deleted user (#:id)',
            'ban' => 'an active ban',              // facts strip — banned. NOT "a site ban": BanChecker matches a
            // global OR a scoped (category/forum/ancestor) ban and cannot
            // tell which, so the label must not assert a site-wide scope.
            'none' => 'no matching rule',          // facts strip — deny-by-default
        ],
    ],

    // ── Security → Active delegations (ACP v3 · v3-f, ADR-0087) ──────────────────────────────────────────
    // Time-boxed, ceiling-bounded capability delegation. The co-owner-only Security pane's strings.
    'security' => [
        'delegations' => [
            'intro' => 'Hand an individual a single capability for a bounded window. The grant can never exceed your own access, auto-expires (up to 30 days), and you can revoke it early at any time.',
            'recipient' => 'Recipient (id, username, or email)',
            'capability' => 'Capability',
            'choose_capability' => 'Choose a capability…',
            'scope' => 'Scope',
            'scope_global' => 'site-wide',
            'days' => 'Days',
            'grant_action' => 'Delegate',
            'cap_hint' => 'Up to :days days; the window is capped automatically.',
            'active_heading' => 'Active delegations',
            'by_until' => 'granted by :by · expires :expires',
            'empty' => 'No active delegations.',
            'revoke_action' => 'Revoke',
            'revoke_confirm' => 'Revoke this delegation?',
            'confirm' => 'Confirm',
            'cancel' => 'Cancel',
            'granted' => 'Delegated to :user — expires :expires.',
            'revoked' => 'Delegation revoked.',
            'no_user' => 'No user matched “:ref”.',
        ],
    ],
];
