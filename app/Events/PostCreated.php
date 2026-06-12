<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Events;

use App\Models\Post;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reply was created and committed (P2-M3). Dispatched by PostService::reply AFTER the write commits and
 * only for an APPROVED reply. The opening post of a topic is NOT a reply and is never dispatched here (it is
 * covered by TopicCreated), so the feed shows "created a topic" once, not also "replied".
 */
final class PostCreated
{
    use Dispatchable;

    public function __construct(public readonly Post $post) {}
}
