<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Jobs;

use App\Deliverability\SuppressionGate;
use App\Mail\DigestMail;
use App\Models\DigestQueueItem;
use App\Models\DigestRun;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Spike P2 — the digest SENDER (queued; drained by the existing M5 cron `queue:work`). Carries only the
 * run id (SerializesModels-safe, never the rendered body) and is IDEMPOTENT: a run already `sent` is a
 * no-op, so a `--tries` retry after a successful send never re-delivers. The only residual double-delivery
 * window is SMTP-accept → status-commit — identical to and no wider than the live immediate path.
 *
 * The send gate is re-checked HERE (not only at assembly), so a suppression / unsubscribe that landed
 * between enqueue and drain still suppresses the mail (GO criterion 5).
 */
final class SendDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $runId) {}

    public function handle(SuppressionGate $gate): void
    {
        $run = DigestRun::find($this->runId);
        if (! $run instanceof DigestRun || $run->status === 'sent') {
            return; // dedup guard — already delivered (or vanished)
        }

        $user = User::find($run->user_id);
        if (! $user instanceof User || ! $gate->allowsDigest($user)) {
            // Recipient gone, suppressed, or unsubscribed after enqueue → consume the period, send nothing.
            $this->markSent($run, 0);

            return;
        }

        $items = DigestQueueItem::query()->where('digest_run_id', $run->getKey())->orderBy('id')->get();
        if ($items->isEmpty()) {
            $this->markSent($run, 0);

            return;
        }

        Mail::to($user->email)->send(new DigestMail((int) $run->getKey(), $user, $items->all()));

        $this->markSent($run, $items->count());
    }

    private function markSent(DigestRun $run, int $count): void
    {
        $run->forceFill(['status' => 'sent', 'sent_at' => now(), 'item_count' => $count])->save();
    }
}
