<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Membership\TierPerks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A membership tier (Phase 4 · M5.1) — an admin-defined level whose `perks` (a list of {@see TierPerks} keys)
 * are granted to active subscribers through the permission engine. Pure catalogue: it charges nothing.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $price_cents
 * @property string $currency
 * @property string $interval
 * @property list<string>|null $perks
 * @property bool $is_active
 */
class MembershipTier extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'price_cents', 'currency', 'interval', 'perks', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'perks' => 'array',
            'is_active' => 'boolean',
            'price_cents' => 'integer',
            'sort' => 'integer',
        ];
    }

    /** @return HasMany<MemberSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(MemberSubscription::class, 'tier_id');
    }

    public function isFree(): bool
    {
        return (int) $this->price_cents === 0;
    }

    /** A display label for the price, e.g. "$5.00 / month". Free tiers read "Free". */
    public function priceLabel(): string
    {
        if ($this->isFree()) {
            return 'Free';
        }

        $amount = number_format($this->price_cents / 100, 2);
        $cadence = match ($this->interval) {
            'monthly' => ' / month',
            'yearly' => ' / year',
            default => '',
        };

        return mb_strtoupper($this->currency).' '.$amount.$cadence;
    }

    /** The valid perk keys this tier grants (mistyped/legacy keys are filtered out). @return list<string> */
    public function perkKeys(): array
    {
        return TierPerks::sanitize($this->perks ?? []);
    }
}
