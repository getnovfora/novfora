<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One message in a {@see Conversation} (P2-M2 Half-B). The body is stored with the SAME canonical columns and
 * cast as a Post (`body_canonical` round-trips as a PHP array), rendered through the single ContentRenderer
 * path — never a second sanitizer. `user_id` is the author and is anonymisable (ADR-0025): on account deletion
 * it is set to NULL (the body is retained so the thread stays coherent for remaining participants), so
 * {@see author()} may resolve to null → renders as "[Deleted]" exactly like a pseudonymised post.
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'conversation_id' => 'integer',
        'user_id' => 'integer',
        'body_canonical' => 'array',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Report-on-PM reuses the existing Report polymorph (reportable_type = Message::class). @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }
}
