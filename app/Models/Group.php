<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    /** Membership models (ACP v3 · v3-e) — HOW humans join. Auto-promotion is orthogonal (the system can add
     *  a user to ANY model's group when its `auto_promotion` rule tree is satisfied). */
    public const MEMBERSHIP_ADMIN = 'admin';     // manual add/remove via the ACP only (unchanged default)

    public const MEMBERSHIP_REQUEST = 'request'; // a user requests; an admin/approver decides (queue)

    public const MEMBERSHIP_OPEN = 'open';       // a public "Join" button (anti-spam/trust-gated)

    public const MEMBERSHIP_MODELS = [self::MEMBERSHIP_ADMIN, self::MEMBERSHIP_REQUEST, self::MEMBERSHIP_OPEN];

    // Explicit allowlist (phase-1.5 F-G): group identity/priority drives permission resolution, so it must
    // not be mass-assignable from request data. Written only by GroupSeeder / GroupManager / the Acl test
    // helper. `color`/`description` (ACP v2) are cosmetic — they don't feed resolution — so they're safe to
    // include; the structural slug/type/priority/is_system stay server-written only. `membership_model`/
    // `is_public`/`auto_promotion` (v3-e) are config the GroupManager validates against a closed vocabulary.
    // `show_on_staff_page`/`show_staff_icon`/`staff_title` (ACP v3 · v3-g) are DISPLAY-ONLY cosmetics — they
    // drive the staff flair + the public /staff roster and never feed permission resolution, so they are safe to
    // mass-assign (like color/description), unlike the structural slug/type/priority/is_system.
    protected $fillable = ['slug', 'name', 'color', 'description', 'type', 'priority', 'is_system', 'auto_promotion', 'membership_model', 'is_public', 'show_on_staff_page', 'show_staff_icon', 'staff_title'];

    protected $casts = [
        'is_system' => 'boolean',
        'is_public' => 'boolean',
        'show_on_staff_page' => 'boolean',
        'show_staff_icon' => 'boolean',
        'priority' => 'integer',
        'auto_promotion' => 'array',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['is_primary', 'is_primary_locked']);
    }

    /** @return HasMany<GroupJoinRequest, $this> */
    public function joinRequests(): HasMany
    {
        return $this->hasMany(GroupJoinRequest::class);
    }

    public function membershipModel(): string
    {
        $model = (string) ($this->membership_model ?? self::MEMBERSHIP_ADMIN);

        return in_array($model, self::MEMBERSHIP_MODELS, true) ? $model : self::MEMBERSHIP_ADMIN;
    }

    /**
     * Whether a self-service join path (open / request) is permitted for this group AT ALL. System + trust
     * groups are engine-managed (Guests/Members auto-assigned, tl0…tl4 by the trust engine), so they never
     * expose a human join path regardless of their stored membership_model — defence-in-depth beside the
     * GroupMembershipService guards.
     */
    public function allowsSelfService(): bool
    {
        return ! $this->is_system && $this->type !== 'trust';
    }

    public function allowsOpenJoin(): bool
    {
        return $this->allowsSelfService() && $this->membershipModel() === self::MEMBERSHIP_OPEN;
    }

    public function acceptsJoinRequests(): bool
    {
        return $this->allowsSelfService() && $this->membershipModel() === self::MEMBERSHIP_REQUEST;
    }

    public function isPublic(): bool
    {
        return (bool) $this->is_public;
    }
}
