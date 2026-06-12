<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use RuntimeException;

/**
 * A merge or split was refused (P2-M4). The `reason` code is stable for tests + UI branching; the message is
 * user-safe (surfaced in the moderation modal). Never thrown for a silently-skipped bulk item — bulk
 * moderation skips, it does not raise (see BulkModerationService).
 */
final class TopicModerationException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notAuthorized(): self
    {
        return new self('not_authorized', 'You are not allowed to moderate one of these topics.');
    }

    public static function outranked(): self
    {
        return new self('outranked', 'You cannot act on content by a higher-ranked member.');
    }

    public static function sameTopic(): self
    {
        return new self('same_topic', 'A topic cannot be merged into itself.');
    }

    public static function invalidState(): self
    {
        return new self('invalid_state', 'One of these topics can no longer be merged or split.');
    }

    public static function nothingToSplit(): self
    {
        return new self('nothing_to_split', 'Select at least one post and provide a title for the new topic.');
    }

    public static function postsNotInTopic(): self
    {
        return new self('posts_not_in_topic', 'Some of the selected posts are not part of this topic.');
    }

    public static function cannotMoveOpeningPost(): self
    {
        return new self('cannot_move_op', 'The opening post cannot be split out — it anchors the topic.');
    }
}
