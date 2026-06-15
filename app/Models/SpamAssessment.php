<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded spam-intelligence assessment for a HELD post (Phase 4 · M6.1) — the score, per-signal breakdown,
 * and moderation reasons that put it in the queue. Read by the M6.2 review surface. Append-only.
 *
 * @property int $id
 * @property ?int $post_id
 * @property int $user_id
 * @property int $score
 * @property array<string,int>|null $signals
 * @property list<string>|null $reasons
 */
class SpamAssessment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['post_id', 'user_id', 'score', 'signals', 'reasons'];

    protected function casts(): array
    {
        return [
            'signals' => 'array',
            'reasons' => 'array',
            'score' => 'integer',
        ];
    }

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
