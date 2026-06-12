<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Community;

use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A rendered activity-feed row (P2-M3): the primitive cache row rehydrated with its actor + subject, resolved
 * AFTER the cache boundary. A null/soft-deleted actor or subject is a tombstone — the view never links to a
 * gone target and never throws.
 */
final class ActivityFeedItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $verb,
        public readonly ?User $actor,
        public readonly ?Model $subject,
        public readonly ?Carbon $createdAt,
    ) {}

    /** The topic this row links to: the subject itself when it's a Topic, or the reply/reacted post's topic. */
    public function topic(): ?Topic
    {
        if ($this->subject instanceof Topic) {
            return $this->subject;
        }
        if ($this->subject instanceof Post) {
            return $this->subject->topic;
        }

        return null;
    }

    /** True when the subject (or its topic) is gone or soft-deleted → render a tombstone, never a link. */
    public function isMissing(): bool
    {
        $subject = $this->subject;
        if ($subject === null || ($subject instanceof Topic && $subject->trashed()) || ($subject instanceof Post && $subject->trashed())) {
            return true;
        }

        $topic = $this->topic();

        return $topic === null || $topic->trashed();
    }

    public function title(): ?string
    {
        return $this->topic()?->title;
    }

    /** Link to the topic (with a #post anchor for a reply/reaction), or null when the target is gone. */
    public function url(): ?string
    {
        if ($this->isMissing()) {
            return null;
        }

        $topic = $this->topic();
        if ($topic === null) {
            return null;
        }

        $url = route('topics.show', $topic);

        return $this->subject instanceof Post ? $url.'#post-'.$this->subject->getKey() : $url;
    }
}
