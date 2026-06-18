<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
| Profile (view + edit) UI strings (i18n sweep, ADR-0079; extends ADR-0043/0073). This `en` set is
| authoritative — other locales fall back here per missing string.
*/

return [
    // Edit profile (profiles/edit.blade.php)
    'edit_title' => 'Edit profile',
    'shell_title' => 'Profile',
    'edit_intro' => 'Update how you appear across :app. Your signature and custom fields show on your public profile and posts.',
    'signature_label' => 'Signature (Markdown)',
    'signature_hint' => 'Markdown is rendered through the canonical sanitisation pipeline.',
    'avatar' => 'Avatar',
    'cover_image' => 'Cover image',
    'save_profile' => 'Save profile',

    // Public profile (profiles/show.blade.php)
    'trust_level' => 'Trust level',
    'reputation' => 'reputation',
    'badges' => 'Badges',
    'staff_tools' => 'Staff tools',
    'staff_tools_intro' => 'Moderator actions for this member.',
    'delete_account' => 'Delete account…',
    'about' => 'About',
    'signature' => 'Signature',
];
