<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PostCreated;
use App\Models\Activity;

/**
 * Logs a `post.created` activity for a reply (P2-M3). AUTO-DISCOVERED via the handle(PostCreated) signature.
 * Subject = the reply; scope = the reply's forum (via its topic). The event is dispatched post-commit.
 */
final class RecordPostActivity
{
    public function handle(PostCreated $event): void
    {
        $post = $event->post;

        Activity::record(Activity::VERB_POST_CREATED, $post, (int) $post->user_id, $post->topic?->forum_id);
    }
}
