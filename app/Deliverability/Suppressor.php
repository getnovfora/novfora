<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability;

use App\Deliverability\Bounce\BounceEvent;
use App\Models\EmailSuppression;
use App\Support\Audit;

/**
 * Spike P2 — the one place an address is added to / removed from the deliverability suppression list, shared
 * by every ingestion path (webhook, IMAP poll, VERP) and the manual ACP floor. Idempotent (the email column
 * is UNIQUE; addresses are normalised to lower-case) and audited. Reasons: bounce | complaint | manual.
 */
final class Suppressor
{
    private const REASONS = ['bounce', 'complaint', 'manual'];

    /** Suppress an address. Returns true only if it was newly added. Invalid/empty addresses are ignored. */
    public function suppress(string $email, string $reason): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $reason = in_array($reason, self::REASONS, true) ? $reason : BounceEvent::BOUNCE;

        $row = EmailSuppression::firstOrCreate(
            ['email' => $email],
            ['reason' => $reason, 'created_at' => now()],
        );

        if ($row->wasRecentlyCreated) {
            Audit::log('deliverability.suppressed', $row, ['email' => $email, 'reason' => $reason]);

            return true;
        }

        return false;
    }

    /** Remove an address from the suppression list (manual un-suppress in the ACP). Returns true if removed. */
    public function unsuppress(string $email): bool
    {
        $email = strtolower(trim($email));
        $deleted = EmailSuppression::query()->whereRaw('LOWER(email) = ?', [$email])->delete();

        if ($deleted > 0) {
            Audit::log('deliverability.unsuppressed', null, ['email' => $email]);

            return true;
        }

        return false;
    }

    /** Apply a parsed event, suppressing only when it warrants it. Returns true if a new suppression landed. */
    public function applyEvent(BounceEvent $event): bool
    {
        return $event->shouldSuppress() && $this->suppress($event->email, $event->reason());
    }
}
