<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Community;

use App\Events\ReputationAwarded;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The reputation ledger + denorm writer (P2-M5 ⚙, ADR-0028). users.reputation_points is a denormalised
 * SUM(points) over reputation_events; this service is the ONLY writer of either, and every mutation keeps
 * the two reconciled by construction:
 *
 *   • award()  — insertOrIgnore on the UNIQUE(source_type, source_id) idempotency key. The denorm is
 *     adjusted ONLY when a row was actually inserted, with an ATOMIC SQL increment (column = column + N,
 *     never read-modify-write) — a double-fired event, a retried queue job, or a concurrent duplicate is
 *     a provable no-op for both the ledger and the sum.
 *   • revoke() — deletes the source's event; the denorm is decremented by the STORED points (the weight at
 *     award time, immune to later config changes) only when this caller's delete actually removed the row,
 *     so two concurrent revokes can never double-decrement.
 *   • recomputeFor() — the authoritative self-heal: overwrite the denorm with the ledger SUM. Used by the
 *     nevo:reputation:recompute cron and by the account-deletion cascade AFTER it prunes ledger rows
 *     (never run ±deltas through a half-deleted graph — ADR-0025 extension).
 *
 * Out-of-order delivery on a multi-worker (enhanced) tier can transiently misorder a revoke/award pair for
 * the same source; each operation is individually consistent and the hourly recompute reconciles any
 * residue (the cron-only baseline drains the queue serially, so it never reorders — ADR-0011).
 */
final class ReputationService
{
    /**
     * Award $points to $recipient, sourced from $source. Returns true only when a NEW ledger row was
     * written (and the denorm incremented). A zero-point award writes nothing — the slot stays free.
     */
    public function award(User $recipient, Model $source, int $points): bool
    {
        if ($points === 0 || ! $recipient->getKey() || ! $source->getKey()) {
            return false;
        }

        $inserted = DB::transaction(function () use ($recipient, $source, $points): bool {
            $landed = DB::table('reputation_events')->insertOrIgnore([
                'user_id' => (int) $recipient->getKey(),
                'source_type' => $source->getMorphClass(),
                'source_id' => (int) $source->getKey(),
                'points' => $points,
                'created_at' => now(),
            ]) > 0;

            if ($landed) {
                // Atomic in-place increment — never read-modify-write (concurrent awards to the same
                // recipient from different sources must both land).
                User::whereKey($recipient->getKey())->increment('reputation_points', $points);
            }

            return $landed;
        });

        // The rep-threshold badge trigger (P2-M5) — only a REAL insert fires it, so a replayed award
        // never re-triggers; only an upward move matters (badges are permanent, negatives award nothing).
        if ($inserted && $points > 0) {
            ReputationAwarded::dispatch($recipient);
        }

        return $inserted;
    }

    /**
     * Revoke whatever $source awarded (if anything). Returns true only when an event row was actually
     * removed by THIS call (and the recipient's denorm decremented by the stored points).
     */
    public function revoke(Model $source): bool
    {
        if (! $source->getKey()) {
            return false;
        }

        return DB::transaction(function () use ($source): bool {
            $event = DB::table('reputation_events')
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', (int) $source->getKey())
                ->first();

            if ($event === null) {
                return false;
            }

            // Delete BY PRIMARY KEY and gate on the affected-row count: of two concurrent revokes, exactly
            // one observes a deletion and decrements — the loser is a no-op.
            $deleted = DB::table('reputation_events')->where('id', $event->id)->delete() > 0;

            if ($deleted) {
                // increment() with the negated stored points = an atomic `column = column - N`; the stored
                // value (not the live config weight) is what was actually counted at award time.
                User::whereKey($event->user_id)->increment('reputation_points', -(int) $event->points);
            }

            return $deleted;
        });
    }

    /**
     * Align $source's award with $points — the reaction TYPE-CHANGE path (single-choice reactions reuse
     * the same row across type changes, so the UNIQUE source slot must be re-pointed, not re-inserted).
     * Already-aligned (same recipient, same points) → pure no-op, which is exactly what a double-fired
     * event resolves to. Differing → revoke the stored award, then award the new points (each step
     * individually idempotent, so any interleaving converges). $points of 0 clears the award.
     */
    public function syncSourceAward(User $recipient, Model $source, int $points): void
    {
        $existing = DB::table('reputation_events')
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', (int) $source->getKey())
            ->first();

        if ($existing !== null
            && (int) $existing->points === $points
            && (int) $existing->user_id === (int) $recipient->getKey()) {
            return; // already counted at this weight — double-fire lands here
        }

        if ($existing !== null) {
            $this->revoke($source);
        }

        $this->award($recipient, $source, $points);
    }

    /**
     * Authoritatively overwrite each user's denorm with their ledger SUM — the self-heal path (cron +
     * deletion cascade). Users with no surviving ledger rows reset to 0. Idempotent; bounded by chunking.
     *
     * @param  list<int>  $userIds
     */
    public function recomputeFor(array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return;
        }

        foreach (array_chunk($userIds, 500) as $chunk) {
            $sums = DB::table('reputation_events')
                ->whereIn('user_id', $chunk)
                ->groupBy('user_id')
                ->selectRaw('user_id, SUM(points) AS total')
                ->pluck('total', 'user_id');

            foreach ($chunk as $id) {
                User::whereKey($id)->update(['reputation_points' => (int) ($sums[$id] ?? 0)]);
            }
        }
    }
}
