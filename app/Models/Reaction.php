<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single user's reaction to a post (P2-M1). Single-choice: the UNIQUE(post_id,user_id) index means a user
 * can hold at most one reaction type per post at a time; ReactionService is the only write path and keeps
 * `post_reaction_counts` in sync.
 */
class Reaction extends Model
{
    /** @use HasFactory<ReactionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'post_id' => 'integer',
        'user_id' => 'integer',
    ];

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
