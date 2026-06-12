<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TopicCreated;
use App\Models\Activity;

/**
 * Logs a `topic.created` activity (P2-M3). AUTO-DISCOVERED via the handle(TopicCreated) signature — like
 * SendReactionNotification, do NOT also Event::listen() it. The event is dispatched post-commit, so
 * Activity::record runs safely after the topic exists.
 */
final class RecordTopicActivity
{
    public function handle(TopicCreated $event): void
    {
        $topic = $event->topic;

        // At creation time the topic has its author + forum set (the event only fires post-commit on create).
        Activity::record(Activity::VERB_TOPIC_CREATED, $topic, (int) $topic->user_id, (int) $topic->forum_id);
    }
}
