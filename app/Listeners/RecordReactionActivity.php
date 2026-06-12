<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Reacted;
use App\Models\Activity;

/**
 * Logs a `react.given` activity (P2-M3). AUTO-DISCOVERED via the handle(Reacted) signature — a SECOND
 * listener on Reacted alongside SendReactionNotification. Reacted is dispatched post-commit and only on an
 * add/change (never a toggle-off), so each row is a genuine "gave a reaction". Subject = the reacted post;
 * scope = that post's forum (via its topic).
 */
final class RecordReactionActivity
{
    public function handle(Reacted $event): void
    {
        $post = $event->post;

        Activity::record(Activity::VERB_REACT_GIVEN, $post, (int) $event->actor->getKey(), $post->topic?->forum_id);
    }
}
