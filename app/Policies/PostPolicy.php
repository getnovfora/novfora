<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use App\Permissions\Scope;

/**
 * Post-level authorization through the permission-mask engine (ADR-0006). "own vs any" resolves against the
 * post's topic scope: an actor may edit/delete a post if they hold the `*.any` permission there, or it's
 * their own post and they hold `*.own`. Auto-discovered by Laravel (Post → PostPolicy).
 */
class PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        $scope = Scope::thread((int) $post->topic_id);

        return $user->canDo('post.edit.any', $scope)
            || ($post->user_id === $user->id && $user->canDo('post.edit.own', $scope));
    }

    public function delete(User $user, Post $post): bool
    {
        $scope = Scope::thread((int) $post->topic_id);

        return $user->canDo('post.delete.any', $scope)
            || ($post->user_id === $user->id && $user->canDo('post.delete.own', $scope));
    }

    /** Restoring from the recycle bin is a moderator capability. */
    public function restore(User $user, Post $post): bool
    {
        return $user->canDo('post.delete.any', Scope::thread((int) $post->topic_id));
    }
}
