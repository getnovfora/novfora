<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A multi-participant private conversation (P2-M2 Half-B). Participants live in the `conversation_user` pivot;
 * messages reuse the post canonical content pipeline. `created_by` is anonymisable (ADR-0025) — it may be NULL
 * once the starter deletes their account; the conversation survives while ≥1 participant remains. All
 * authorization is participant-only via ConversationPolicy — there is no scope-tree ACL entry for a PM.
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'created_by' => 'integer',
        'last_message_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    /**
     * The participant pivot rows (used for unread, can_invite, and the deletion cascade). Distinct from
     * {@see participants()} which hydrates the User models.
     *
     * @return HasMany<ConversationParticipant, $this>
     */
    public function participantRows(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_user')
            ->withPivot(['last_read_at', 'left_at', 'can_invite'])
            ->withTimestamps();
    }
}
