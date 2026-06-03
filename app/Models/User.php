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
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['username', 'name', 'display_name', 'email', 'password', 'trust_level', 'status', 'tenant_id'])]
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
        ];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)->withPivot('is_primary')->withTimestamps();
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
}
