<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Events;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reaction was ADDED or CHANGED on a post (P2-M1). Emitted by ReactionService as a forward seam so later
 * milestones can consume reactions without a circular dependency:
 *   - P2-M2 wires reaction notifications (adds a 'reaction' event type to the Notifier),
 *   - P2-M3 (held) wires the reputation ledger that maps `type` → a score from config.
 *
 * There is intentionally NO listener in P2-M1: the score weight is inert until reputation lands
 * (amendment #4) and reaction notifications are M2 work. Dispatched only on add/change (not on toggle-off).
 */
final class Reacted
{
    use Dispatchable;

    public function __construct(
        public readonly User $actor,
        public readonly Post $post,
        public readonly string $type,
    ) {}
}
