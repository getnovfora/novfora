<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Events;

use App\Models\Post;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user toggled their reaction OFF (P2-M5) — the unreact half of Reacted, dispatched post-commit by
 * ReactionService on pure removal only (a type CHANGE keeps the row and re-fires Reacted instead).
 * Carries the already-deleted Reaction model: its id is the reputation ledger's source key, so the
 * revoke listener can undo exactly what that row awarded.
 */
final class ReactionRemoved
{
    use Dispatchable;

    public function __construct(
        public readonly User $actor,
        public readonly Post $post,
        public readonly Reaction $reaction,
    ) {}
}
