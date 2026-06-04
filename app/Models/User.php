<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use App\Permissions\PermissionResolver;
use App\Permissions\Scope;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

// The mass-assignable set is intentionally NARROW (security by default). Privilege/state columns
// (`trust_level`, `status`), the server-rendered HTML cache (`signature_html`), and storage paths
// (`avatar_path`, `cover_path`) are deliberately EXCLUDED: they are written only by server code via
// forceFill / direct assignment (TrustLevelManager, WarningService, SpamCleaner, BanController,
// ProfileController, InstallRunner), never from request input — so a stray `->update($request->...)`
// cannot self-promote trust, lift a ban, smuggle unsanitised HTML into a signature, or cross the dormant
// multi-tenant seam (`tenant_id`, ADR-0004 — removed from the fillable set in phase-1.5 F-H).
#[Fillable(['username', 'name', 'display_name', 'email', 'password', 'signature_doc', 'signature_format'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
            'trust_level' => 'integer',
            'signature_doc' => 'array',
        ];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)->withPivot('is_primary')->withTimestamps();
    }

    /** @return HasMany<CustomFieldValue, $this> */
    public function customFieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /** @return list<int> the user's group ids (primary + secondary) */
    public function groupIds(): array
    {
        return $this->groups->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** A short, stable signature of the user's group-set — part of the resolved-permission cache key. */
    public function groupSignature(): string
    {
        $ids = $this->groupIds();
        sort($ids);

        return substr(md5(implode(',', $ids)), 0, 12);
    }

    public function canDo(string $permission, Scope $scope): bool
    {
        return app(PermissionResolver::class)->can($this, $permission, $scope);
    }

    public function isStaff(): bool
    {
        return $this->groups->whereIn('slug', ['admins', 'moderators'])->isNotEmpty();
    }

    /** Admins (the top system group) may take moderation actions against anyone (phase-1.5 F-F). */
    public function isAdmin(): bool
    {
        return $this->groups->pluck('slug')->contains('admins');
    }

    /** A user's effective rank = the highest priority among their groups (phase-1.5 F-F rank check). */
    public function rankPriority(): int
    {
        return (int) ($this->groups->max('priority') ?? 0);
    }

    /**
     * A transient (non-persisted) anonymous visitor that belongs to the Guests system group, so the
     * SAME permission engine resolves anonymous browsing (security §1.5 "guests-as-group") — no second
     * code path. Its null key + guests group-signature make the resolved verdict cacheable per guest.
     */
    public static function guest(): self
    {
        $guest = new self;
        $guest->exists = false;

        $group = Group::where('slug', 'guests')->first();
        $guest->setRelation('groups', $group ? collect([$group]) : collect());

        return $guest;
    }
}
