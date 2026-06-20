<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Source-of-truth for a per-forum moderator assignment (ACP v3 · v3-b, ADR-0085). The roster lives here;
 * App\Permissions\ForumModeratorProjector mirrors the row into forum-scope acl_entries, which is all the
 * PermissionResolver ever reads (G1). A row names EITHER a seeded preset bundle (`bundle` slug) OR a custom
 * role (`role_id`), never both — {@see role()} resolves the concrete Role the projector expands.
 *
 * @property string $holder_type user|group
 * @property int $holder_id
 * @property int $forum_id
 * @property ?int $role_id
 * @property ?string $bundle
 */
class ModeratorAssignment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'holder_id' => 'integer',
        'forum_id' => 'integer',
        'role_id' => 'integer',
    ];

    /** @return BelongsTo<Forum, $this> */
    public function forum(): BelongsTo
    {
        return $this->belongsTo(Forum::class);
    }

    /** The custom role, when this assignment uses one (null for a preset bundle). @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** The holder model (User or Group), resolved from the polymorphic holder_type/holder_id. */
    public function holder(): ?Model
    {
        return match ($this->holder_type) {
            'user' => User::find($this->holder_id),
            'group' => Group::find($this->holder_id),
            default => null,
        };
    }
}
