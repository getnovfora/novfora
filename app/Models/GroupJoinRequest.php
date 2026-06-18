<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ACP v3 · v3-e (ADR-0083). A user's request to join a `request`-membership-model group, awaiting an
 * admin/approver decision. Written only by GroupMembershipService (request / approve / deny) — never
 * mass-assigned from request input, so the narrow allowlist below excludes nothing security-relevant.
 */
class GroupJoinRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DENIED = 'denied';

    protected $fillable = ['user_id', 'group_id', 'status', 'decided_by', 'decided_at'];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @param Builder<GroupJoinRequest> $query */
    public function scopePending($query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
