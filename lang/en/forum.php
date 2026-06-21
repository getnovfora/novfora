<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
| Forum-domain UI strings (i18n sweep, ADR-0079; extends ADR-0043/0073). Front-end board index, board view,
| topic view, create/edit, and the recycle bin. This `en` set is authoritative — other locales fall back here
| per missing string. Cross-cutting words (Delete/Save/Cancel/Forums/Edit) live in common.php.
|
| NOTE on counts: the board/row markup renders the NUMBER (styled) separately from an always-plural suffix
| word ("3" + "replies"). Those suffix words are kept as STATIC keys (not trans_choice) so the English output
| stays byte-for-byte identical; proper singular/plural is deferred (it would change the n=1 output).
*/

return [
    // Board index (forum/index.blade.php)
    'no_forums_title' => 'No forums yet',
    'no_forums_body' => 'Once forums are created, they’ll show up here for everyone to browse.',

    // Forum/board row (forum/partials/forum-row.blade.php)
    'topics' => 'topics',                       // count suffix
    'posts' => 'posts',                         // count suffix
    'updated_ago' => 'updated :ago',
    'latest_activity' => 'Latest activity',
    'no_posts_yet' => 'No posts yet',
    'topics_label' => 'Topics',                 // sr-only
    'posts_label' => 'Posts',                   // sr-only

    // Board view (forum/show.blade.php)
    'new_topic' => 'New topic',
    'start_a_topic' => 'Start a topic',
    'select' => 'Select',
    'done' => 'Done',
    'sub_boards' => 'Sub-boards',
    'filter' => 'Filter:',
    'filter_all' => 'All',
    'col_subject' => 'Subject',
    'col_replies' => 'Replies',
    'col_views' => 'Views',
    'col_last_post' => 'Last post',
    'by' => 'by',
    'last_by' => 'last by',
    'no_replies_yet' => 'No replies yet',
    'replies' => 'replies',                     // count suffix
    'views' => 'views',                         // count suffix
    'empty_topics_title' => 'No topics here yet',
    'empty_topics_can_post' => 'Be the first to start the conversation in this forum.',
    'empty_topics_guest' => 'Check back soon — there’s nothing posted here right now.',

    // Topic view (forum/topic.blade.php)
    'pinned' => 'Pinned',
    'locked' => 'Locked',
    'pin' => 'Pin',
    'unpin' => 'Unpin',
    'lock' => 'Lock',
    'unlock' => 'Unlock',
    'select_posts' => 'Select posts',
    'done_selecting' => 'Done selecting',
    'select_this_post' => 'Select this post',
    'confirm_trash_topic' => 'Move this topic to the recycle bin?',
    'confirm_delete_post' => 'Delete this post?',
    'role_admin' => 'Admin',
    'role_moderator' => 'Moderator',
    // Staff flair role labels (ACP v3 · v3-g) — the canonical labels User::staffRole() maps to.
    'role_co_owner' => 'Co-owner',
    'role_administrator' => 'Administrator',
    'role_forum_moderator' => 'Forum moderator',
    // "The Team" public staff roster (/staff, ACP v3 · v3-g).
    'staff_page_title' => 'The Team',
    'staff_page_intro' => 'Meet the people who keep this community running.',
    'staff_empty' => 'There are no staff members to show yet.',
    'staff_heading_co_owner' => 'Co-owners',
    'staff_heading_administrator' => 'Administrators',
    'staff_heading_moderator' => 'Moderators',
    'staff_heading_forum_moderator' => 'Forum moderators',
    'joined_label' => 'Joined',                 // sr-only
    'joined_on' => 'Joined :date',
    'post_count' => ':count posts',
    'edited' => 'edited',
    'awaiting_approval' => 'awaiting approval',
    'report' => 'Report',
    'locked_no_replies' => 'This topic is locked — no new replies can be posted.',
    'join_to_reply' => 'Join the conversation to leave a reply.',
    'sign_in_to_reply' => 'Sign in to reply',
    'related_topics' => 'Related topics',

    // Create topic / edit post (forum/create-topic.blade.php, forum/edit-post.blade.php)
    'new_topic_in' => 'New topic in :forum',
    'back_to_topic' => 'Back to topic',

    // Recycle bin (forum/recycle-bin.blade.php)
    'recycle_bin' => 'Recycle bin',
    'recycle_intro' => 'Soft-deleted content you can restore. Hard purge is a separate, audited maintenance job.',
    'deleted_topics' => 'Deleted topics',
    'deleted_posts' => 'Deleted posts',
    'restore' => 'Restore',
    'in_x' => 'in :name',
    'post_n' => 'Post #:id',
    'empty_deleted_topics_title' => 'No deleted topics',
    'empty_deleted_topics_body' => 'Topics you remove will rest here until restored or purged.',
    'empty_deleted_posts_title' => 'No deleted posts',
    'empty_deleted_posts_body' => 'Removed posts can be restored from here.',
];
