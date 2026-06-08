<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability\Digest;

use App\Deliverability\SuppressionGate;
use App\Jobs\SendDigestJob;
use App\Models\DigestPreference;
use App\Models\DigestQueueItem;
use App\Models\DigestRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spike P2 — the cron-batched digest assembler (GO criterion 1, the GO-blocker). One `tick()` is one cron
 * run. Correctness is exactly-once ASSEMBLY, guaranteed by a committed UNIQUE(user_id,cadence,period_key)
 * row created inside a transaction — NOT by the schedule lock (which is only a contention belt). The
 * sequence per due user:
 *
 *   BEGIN  →  INSERT digest_runs (claim; a racing tick collides on the unique index and rolls back)
 *          →  claim a bounded, ordered batch of the user's unclaimed items INTO the run
 *          →  flip status to 'built'
 *   COMMIT  ← run row + item-claim commit together, or not at all (kill-safe: no drop, no double)
 *   AFTER:    dispatch SendDigestJob, then stamp mailed_at (two-phase self-heal flag)
 *
 * A run COMMITTED as 'built' but never enqueued (process died before dispatch) is recovered by the
 * self-heal scan at the top of the next tick. The send itself is at-least-once (the queue retries), with
 * the same narrow SMTP-accept→commit double-delivery window the live immediate path already accepts.
 *
 * NB: not `final` so the kill-safety test can override {@see afterClaim()} to inject a mid-transaction fault.
 */
class DigestAssembler
{
    public function __construct(private readonly SuppressionGate $gate) {}

    /** Run one assembler tick. Returns the number of digests dispatched (incl. self-healed). */
    public function tick(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $cap = max(0, (int) config('hearth.deliverability.digest.max_users_per_tick', 50));
        $dispatched = 0;

        // Phase 0 — self-heal: re-dispatch runs committed as 'built' whose enqueue never landed (a crash
        // between COMMIT and dispatch left mailed_at NULL). The UNIQUE row blocks re-assembly and the send
        // job is idempotent, so this can never double-assemble or double-send.
        foreach ($this->stuckBuiltRuns($cap) as $run) {
            if ($dispatched >= $cap) {
                return $dispatched;
            }
            $this->dispatchSend($run, $now);
            $dispatched++;
        }

        // Phase 1 — claim + assemble due digests, per cadence, up to the per-tick cap (volume hygiene).
        foreach (DigestPreference::BATCHED as $cadence) {
            $periodKey = PeriodKey::for($cadence, $now);
            foreach ($this->dueUserIds($cadence, $periodKey, $cap - $dispatched) as $userId) {
                if ($dispatched >= $cap) {
                    return $dispatched;
                }
                $user = User::find($userId);
                if (! $user instanceof User) {
                    continue;
                }
                $run = $this->claim($user, $cadence, $periodKey, $now);
                if ($run === null) {
                    continue; // already owned this period, gated off, or nothing to send
                }
                $this->dispatchSend($run, $now);
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /** @return Collection<int,DigestRun> */
    private function stuckBuiltRuns(int $cap): Collection
    {
        if ($cap <= 0) {
            return collect();
        }

        return DigestRun::query()
            ->where('status', 'built')
            ->whereNull('mailed_at')
            ->orderBy('id')
            ->limit($cap)
            ->get();
    }

    /**
     * User ids with unclaimed items for this cadence that do NOT yet have a run for this exact period
     * (so a user already assembled this period is excluded — the cap then only delays, never doubles).
     *
     * @return list<int>
     */
    private function dueUserIds(string $cadence, string $periodKey, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return DigestQueueItem::query()
            ->where('cadence', $cadence)
            ->whereNull('digest_run_id')
            ->whereNotExists(function ($query) use ($cadence, $periodKey) {
                $query->select(DB::raw(1))
                    ->from('digest_runs')
                    ->whereColumn('digest_runs.user_id', 'digest_queue_items.user_id')
                    ->where('digest_runs.cadence', $cadence)
                    ->where('digest_runs.period_key', $periodKey);
            })
            ->orderBy('user_id')
            ->distinct()
            ->limit($limit)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** Atomically claim one user's digest for this period, or null if already owned / gated / empty. */
    private function claim(User $user, string $cadence, string $periodKey, Carbon $now): ?DigestRun
    {
        // Re-check the send gate at claim time (an unsubscribe / suppression after enqueue is honoured here,
        // and again in the send job). A gated user is RETIRED for this period — a terminal run + their items
        // claimed — so they leave the due-scan instead of being re-checked every tick forever (which would
        // also starve the per-tick cap and leak items). Mirrors SendDigestJob::markSent($run, 0).
        if (! $this->gate->allowsDigest($user)) {
            $this->retire($user, $cadence, $periodKey, $now);

            return null;
        }

        $rate = max(1, (int) config('hearth.deliverability.digest.per_user_item_rate', 100));

        try {
            return DB::transaction(function () use ($user, $cadence, $periodKey, $now, $rate): DigestRun {
                // (1) The claim/gate. A concurrent tick attempting the same (user,cadence,period) collides
                // on the UNIQUE index → QueryException → full rollback (caught below). Exactly-once.
                $run = DigestRun::create([
                    'user_id' => $user->getKey(),
                    'cadence' => $cadence,
                    'period_key' => $periodKey,
                    'status' => 'claimed',
                    'item_count' => 0,
                    'claimed_at' => $now,
                ]);

                // (2) Claim a bounded, ordered batch INTO the run. Overflow stays unclaimed → next period.
                $ids = DigestQueueItem::query()
                    ->where('user_id', $user->getKey())
                    ->where('cadence', $cadence)
                    ->whereNull('digest_run_id')
                    ->orderBy('id')
                    ->limit($rate)
                    ->pluck('id');

                if ($ids->isEmpty()) {
                    throw new DigestNothingToSend; // roll back → period not consumed
                }

                DigestQueueItem::query()->whereIn('id', $ids)->update(['digest_run_id' => $run->getKey()]);

                // Test seam (no-op in production): a fault here proves the run row AND the item-claim roll
                // back together under a mid-transaction kill — the crux of "no drop, no double".
                $this->afterClaim($run);

                $run->forceFill(['status' => 'built', 'built_at' => $now, 'item_count' => $ids->count()])->save();

                return $run;
            });
        } catch (DigestNothingToSend) {
            return null;
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null; // a concurrent / prior tick already owns this period
            }
            throw $e;
        }
    }

    /**
     * Consume a period for a GATED (unsubscribed / suppressed) user: write a TERMINAL run (status='sent',
     * item_count=0, mailed_at set so self-heal never touches it) and claim their unclaimed items into it.
     * No send. This makes the user fall out of the due-scan and stops their items leaking — without ever
     * mailing them (GO criterion 5). Idempotent under a concurrent claim via the unique-violation catch.
     */
    private function retire(User $user, string $cadence, string $periodKey, Carbon $now): void
    {
        try {
            DB::transaction(function () use ($user, $cadence, $periodKey, $now): void {
                $run = DigestRun::create([
                    'user_id' => $user->getKey(),
                    'cadence' => $cadence,
                    'period_key' => $periodKey,
                    'status' => 'sent',
                    'item_count' => 0,
                    'claimed_at' => $now,
                    'built_at' => $now,
                    'mailed_at' => $now,
                    'sent_at' => $now,
                ]);

                DigestQueueItem::query()
                    ->where('user_id', $user->getKey())
                    ->where('cadence', $cadence)
                    ->whereNull('digest_run_id')
                    ->update(['digest_run_id' => $run->getKey()]);
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e; // a concurrent tick already owns this period → nothing to do
            }
        }
    }

    /** Dispatch the (idempotent) send job, then stamp mailed_at so self-heal won't re-dispatch it. */
    private function dispatchSend(DigestRun $run, Carbon $now): void
    {
        SendDigestJob::dispatch((int) $run->getKey());
        $run->forceFill(['mailed_at' => $now])->save();
    }

    /**
     * @internal Test seam for the kill-safety test — overridden to throw mid-transaction. No-op here.
     */
    protected function afterClaim(DigestRun $run): void {}

    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 = integrity-constraint violation (MySQL 1062 duplicate key; SQLite 19/2067).
        return $e->getCode() === '23000' || (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
