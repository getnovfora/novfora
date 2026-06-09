<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A poll attached to a topic (P2-M1). One poll per topic (linked both ways: polls.topic_id and the
 * topics.poll_id seam). Vote integrity lives in PollService; this model is data + relations only.
 */
class Poll extends Model
{
    protected $guarded = [];

    protected $casts = [
        'topic_id' => 'integer',
        'is_multiple' => 'boolean',
        'max_choices' => 'integer',
        'is_closed' => 'boolean',
        'closes_at' => 'datetime',
    ];

    /** @return BelongsTo<Topic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /** @return HasMany<PollOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<PollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    /** A poll is closed when explicitly closed OR its close time has passed. */
    public function isClosed(): bool
    {
        return $this->is_closed || ($this->closes_at !== null && $this->closes_at->isPast());
    }
}
