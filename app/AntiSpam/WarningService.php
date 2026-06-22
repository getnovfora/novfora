<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Models\Ban;
use App\Models\User;
use App\Models\Warning;
use App\Models\WarningType;
use App\Moderation\OwnerStrandGuard;
use App\Notifications\Notifier;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Warnings / infractions (security §3). Each warning carries time-decaying points (expires_at); the live
 * cumulative total drives AUTOMATED CONSEQUENCES at configured thresholds (moderate → temp-ban → ban) and,
 * via the trust manager, demotion. The IPS-style "acknowledge before posting is restored" flow is honoured:
 * a moderate restriction lifts only once the member has acknowledged every live warning.
 */
final class WarningService
{
    public function __construct(
        private readonly TrustLevelManager $trust,
        private readonly Notifier $notifier,
        private readonly OwnerStrandGuard $ownerGuard,
    ) {}

    public function issue(User $actor, User $target, WarningType $type, ?string $reason = null): Warning
    {
        $warning = DB::transaction(function () use ($actor, $target, $type, $reason) {
            $warning = Warning::create([
                'user_id' => $target->getKey(),
                'issued_by' => $actor->getKey(),
                'warning_type_id' => $type->getKey(),
                'points' => (int) $type->default_points,
                'reason' => $reason,
                'expires_at' => $type->decay_days ? now()->addDays((int) $type->decay_days) : null,
            ]);

            $consequence = $this->applyConsequence($target->fresh());
            if ($consequence !== null) {
                $warning->forceFill(['action_taken' => $consequence])->save();
            }

            // Cumulative live points may also demote the trust level (and so further gate the account).
            $this->trust->recompute($target->fresh());

            Audit::log('warning.issued', $target, [
                'type' => $type->slug, 'points' => (int) $type->default_points, 'by' => $actor->getKey(),
                // Only report a consequence that was actually applied; a ban WITHHELD by the owner-strand guard
                // is recorded separately by warning.consequence_suppressed (see applyConsequence).
                'consequence' => ($consequence !== null && empty($consequence['suppressed'])) ? $consequence['action'] : null,
            ]);

            return $warning->refresh();
        });

        // Notify the member of the moderation notice (after commit).
        $this->notifier->send($target, 'moderation', $actor, ['url' => route('warnings.index')]);

        return $warning;
    }

    public function acknowledge(User $user, Warning $warning): void
    {
        if ((int) $warning->user_id !== (int) $user->getKey()) {
            return; // a member may only acknowledge their own warnings
        }

        $warning->forceFill(['acknowledged_at' => now()])->save();

        // Posting is restored once every live warning is acknowledged (the moderate restriction lifts).
        $outstanding = Warning::where('user_id', $user->getKey())->live()->whereNull('acknowledged_at')->exists();
        if (! $outstanding && ($user->status ?? null) === 'pending') {
            $user->forceFill(['status' => 'active'])->save();
        }
    }

    /** Apply the highest automated consequence the live point total has crossed. @return array<string,mixed>|null */
    private function applyConsequence(User $target): ?array
    {
        $points = (int) Warning::where('user_id', $target->getKey())->live()->sum('points');
        $thresholds = (array) config('novfora.antispam.warnings.thresholds', []);

        if (isset($thresholds['ban']) && $points >= (int) $thresholds['ban']) {
            // The INDIRECT ban door (the real trap, ADR-0100): an AUTO-consequence may not strand the owner tier
            // any more than a hand-issued ban may. The locked re-read runs inside issue()'s transaction; on a
            // strand we SUPPRESS the lockout but KEEP the warning — the moderation note stands, the owner stays
            // reachable. Fail-closed: the owner is never banned.
            if ($this->ownerGuard->wouldStrandOwnerTierLocked($target)) {
                return $this->suppressedConsequence($target, 'ban', $points);
            }

            $target->forceFill(['status' => 'banned'])->save();
            Ban::firstOrCreate(
                ['user_id' => $target->getKey(), 'type' => 'user', 'scope_type' => 'global'],
                ['reason' => 'Infraction threshold reached'],
            );

            return ['action' => 'ban', 'points' => $points];
        }

        if (isset($thresholds['temp_ban']) && $points >= (int) $thresholds['temp_ban']) {
            // A temp ban is still an absolute lockout for its duration (BanChecker matches the unexpired row), so
            // the same backstop applies — suppress + keep the warning when it would strand the sole owner.
            if ($this->ownerGuard->wouldStrandOwnerTierLocked($target)) {
                return $this->suppressedConsequence($target, 'temp_ban', $points);
            }

            $days = (int) config('novfora.antispam.warnings.temp_ban_days', 7);
            Ban::create([
                'user_id' => $target->getKey(), 'type' => 'user', 'scope_type' => 'global',
                'reason' => 'Infraction threshold reached', 'expires_at' => now()->addDays($days),
            ]);

            return ['action' => 'temp_ban', 'points' => $points, 'days' => $days];
        }

        if (isset($thresholds['moderate']) && $points >= (int) $thresholds['moderate']) {
            if (($target->status ?? 'active') === 'active') {
                $target->forceFill(['status' => 'pending'])->save(); // posts are now held (NewUserModeration)
            }

            return ['action' => 'moderate', 'points' => $points];
        }

        return null;
    }

    /**
     * Record (and audit) that a threshold-crossing ban/temp-ban was WITHHELD by the owner-strand guard. The
     * warning row is still written; only the lockout is suppressed, so the sole owner stays reachable.
     *
     * @return array{action:string, points:int, suppressed:string}
     */
    private function suppressedConsequence(User $target, string $action, int $points): array
    {
        Audit::log('warning.consequence_suppressed', $target, [
            'action' => $action, 'points' => $points, 'reason' => 'owner_strand',
        ]);

        return ['action' => $action, 'points' => $points, 'suppressed' => 'owner_strand'];
    }
}
