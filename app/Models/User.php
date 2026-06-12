<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Models;

use App\Permissions\PermissionResolver;
use App\Permissions\Scope;
use App\Support\GroupColor;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
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

    /** Display-preference vocabulary (P2-M4). posts_per_page/thread_sort are written ONLY by the
     *  ⚡user-preferences SFC (validated against these sets), never mass-assigned — they stay out of #[Fillable]. */
    public const POSTS_PER_PAGE_OPTIONS = [15, 30, 50];

    public const POSTS_PER_PAGE_DEFAULT = 15;

    public const THREAD_SORTS = ['oldest', 'newest'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
            'trust_level' => 'integer',
            'reputation_points' => 'integer',
            'signature_doc' => 'array',
            'posts_per_page' => 'integer',
        ];
    }

    /** @return BelongsToMany<Group, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)->withPivot('is_primary')->withTimestamps();
    }

    /** @return HasMany<CustomFieldValue, $this> */
    public function customFieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /** The private conversations this user participates in (P2-M2 Half-B). @return BelongsToMany<Conversation, $this> */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_user')
            ->withPivot(['last_read_at', 'left_at', 'can_invite'])
            ->withTimestamps();
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

    /** Activity heuristic (P2-M3): "online" = active within the last 15 minutes (last_active_at is written
     *  by the throttled ThrottledLastActive middleware, at most once per 5 minutes per user). */
    public function isOnline(): bool
    {
        $last = $this->last_active_at;

        return $last !== null && Carbon::parse($last)->gt(now()->subMinutes(15));
    }

    /** The viewer's chosen posts-per-thread-page (P2-M4), clamped to the allowed set; null → the default (15). */
    public function postsPerPage(): int
    {
        $value = (int) ($this->posts_per_page ?? self::POSTS_PER_PAGE_DEFAULT);

        return in_array($value, self::POSTS_PER_PAGE_OPTIONS, true) ? $value : self::POSTS_PER_PAGE_DEFAULT;
    }

    /** Whether the viewer reads threads newest-first (P2-M4); null/anything-else → oldest-first (the default). */
    public function threadSortNewestFirst(): bool
    {
        return $this->thread_sort === 'newest';
    }

    /**
     * The group whose colour styles this user's name (ACP v2). RESOLUTION RULE: among the user's groups that
     * have a colour, the one with the highest `priority` wins (ties broken by the higher group id, stable);
     * a group with no colour is ignored. Returns null when no coloured group applies — the name then renders
     * in the normal --ink. Reads the already-loaded `groups` relation (eager-loaded on list pages to avoid
     * N+1); lazy-loads once on single-user pages.
     */
    public function displayGroup(): ?Group
    {
        return $this->groups
            ->filter(fn (Group $g): bool => GroupColor::isValid($g->color))
            ->sortByDesc(fn (Group $g): string => sprintf('%04d:%012d', (int) $g->priority, (int) $g->getKey()))
            ->first();
    }

    /** The CSS custom-property reference for this user's name colour, or null when no coloured group applies. */
    public function nameColor(): ?string
    {
        return GroupColor::cssVar($this->displayGroup()?->color);
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
