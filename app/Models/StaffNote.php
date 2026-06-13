<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private staff-only note about a member (A1). Read/written ONLY through the staff surfaces gated by
 * App\Moderation\StaffNotes — never visible to the subject or to a non-staff viewer. `author_id` carries no
 * foreign key, so the note survives the author's account deletion: the ADR-0025 cascade NULLs it and it then
 * renders as "[Deleted]".
 */
class StaffNote extends Model
{
    protected $guarded = [];

    /**
     * The member the note is ABOUT.
     *
     * @return BelongsTo<User, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The staff member who wrote it (NULL once that account is deleted).
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
