<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Events;

use App\Models\Topic;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A topic was created and committed (P2-M3). Dispatched by PostService::createTopic AFTER the transaction
 * commits and only for an APPROVED topic, so a rolled-back or held-for-moderation topic never logs an
 * activity. Mirrors the post-commit dispatch of Reacted.
 */
final class TopicCreated
{
    use Dispatchable;

    public function __construct(public readonly Topic $topic) {}
}
