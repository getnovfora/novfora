<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply queued to publish at a future time (member tool 2.4). It holds the canonical body until
 * `publish_at`; the publish cron then creates the real Post via PostService and stamps `published_at`
 * (the atomic claim) + `post_id`. Written only through PostScheduler.
 */
class ScheduledPost extends Model
{
    protected $fillable = ['user_id', 'topic_id', 'body_format', 'body_canonical', 'publish_at', 'published_at', 'post_id'];

    protected $casts = [
        'body_canonical' => 'array',
        'publish_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Topic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }
}
