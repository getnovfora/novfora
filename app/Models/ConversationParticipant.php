<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConversationParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single user's membership of a conversation (the `conversation_user` pivot, modelled as a first-class row
 * so unread state, `can_invite`, and the ADR-0025 deletion cascade can query it directly). `left_at` is a
 * soft-leave marker; an active participant has `left_at = NULL`. The row HARD-deletes with the account.
 */
class ConversationParticipant extends Model
{
    /** @use HasFactory<ConversationParticipantFactory> */
    use HasFactory;

    protected $table = 'conversation_user';

    protected $guarded = [];

    protected $casts = [
        'conversation_id' => 'integer',
        'user_id' => 'integer',
        'last_read_at' => 'datetime',
        'left_at' => 'datetime',
        'can_invite' => 'boolean',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
