<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;

/**
 * Whether a viewer may see one post's CONTENT (NF-02, NOV-145) — the object-level twin of TopicController@show,
 * for surfaces that serve a single post's bytes out of band (today: the attachment endpoint). A file must
 * never be more public than the post it belongs to, so this mirrors every fence the topic page applies:
 *   1. club content visibility, then forum.view at the THREAD scope (a guest resolves as the Guests group);
 *   2. the recycle bin: a soft-deleted topic or post is visible only to a topic.moderate holder;
 *   3. the topic moderation fence: a pending topic is visible only to its author or a topic.moderate holder;
 *   4. the post moderation fence: a pending/rejected post is visible only to its author or a topic.moderate
 *      holder (the topic page's per-post filter).
 * Callers layer any intentional extra access (e.g. the uploader of an attachment) on top, explicitly.
 */
final class PostVisibility
{
    public function canView(?User $user, Post $post, Topic $topic): bool
    {
        $viewer = $user ?? User::guest();
        $forum = $topic->forum;
        $scope = $topic->permissionScope();

        if (! $forum instanceof Forum || ! $forum->clubContentVisibleTo($user)
            || ! $viewer->canDo('forum.view', $scope)) {
            return false;
        }

        $canModerate = $user instanceof User && $user->canDo('topic.moderate', $scope);
        if ($canModerate) {
            return true; // staff who can moderate here see held and recycled content (the topic page's rule)
        }

        if ($topic->trashed() || $post->trashed()) {
            return false;
        }

        $viewerId = $user instanceof User ? (int) $user->getKey() : null;
        if ($topic->approved_state !== 'approved' && $viewerId !== (int) $topic->user_id) {
            return false;
        }

        return $post->approved_state === 'approved' || ($viewerId !== null && $viewerId === (int) $post->user_id);
    }
}
