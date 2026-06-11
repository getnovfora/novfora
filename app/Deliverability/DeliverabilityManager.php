<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability;

use App\Deliverability\Bounce\BounceMailbox;
use App\Deliverability\Bounce\BounceParser;
use App\Deliverability\Bounce\BounceReviewQueue;
use App\Deliverability\Webhook\WebhookVerifier;

/**
 * Spike P2 — the tri-path bounce/complaint orchestrator (GO criteria 2 + 4). Detects what's available and
 * degrades in order: provider WEBHOOK (push, handled by the controller) → cron-polled IMAP mailbox →
 * VERP / manual-ACP floor. This class owns the POLL side ({@see ingestAvailable()}) and reports the active
 * path for the ACP. It NEVER throws: with nothing configured the mailbox is the {@see Bounce\NullBounceMailbox}
 * and ingestion is a clean no-op, leaving the always-available VERP + manual floor.
 */
final class DeliverabilityManager
{
    public function __construct(
        private readonly BounceMailbox $mailbox,
        private readonly BounceParser $parser,
        private readonly Suppressor $suppressor,
        private readonly WebhookVerifier $webhook,
        private readonly BounceReviewQueue $reviews,
    ) {}

    /** Poll the configured mailbox, parse each message, apply suppressions. Returns count newly suppressed. */
    public function ingestAvailable(): int
    {
        if (! (bool) config('novfora.deliverability.enabled')) {
            return 0; // dormant
        }

        $newly = 0;

        try {
            $cap = max(1, (int) config('novfora.deliverability.imap.per_tick_cap', 100));
            foreach ($this->mailbox->fetch($cap) as $raw) {
                foreach ($this->parser->parse($raw) as $event) {
                    if ($this->suppressor->applyEvent($event)) {
                        $newly++;
                    }
                }

                // Without VERP the mailbox can't authenticate a sender-supplied address, so parse() above
                // auto-suppressed NOTHING. Surface a permanent-bounce / complaint for STAFF review instead of
                // dropping it (reviewCandidate() returns null when VERP is enabled, so this is a no-op there).
                $candidate = $this->parser->reviewCandidate($raw);
                if ($candidate !== null) {
                    $this->reviews->enqueue($candidate);
                }
            }
        } catch (\Throwable) {
            // forced-absence / transient mailbox failure → never throw; the manual + VERP floor still holds.
        }

        return $newly;
    }

    /** Which ingestion path is active right now — informational for the ACP. */
    public function activePath(): string
    {
        if ($this->webhook->configured()) {
            return 'webhook';
        }
        if ($this->mailbox->available()) {
            return 'imap';
        }

        return 'manual'; // VERP signed Return-Path + manual ACP suppression — always available
    }
}
