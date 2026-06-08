<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability;

use App\Deliverability\Bounce\BounceMailbox;
use App\Deliverability\Bounce\BounceParser;
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
    ) {}

    /** Poll the configured mailbox, parse each message, apply suppressions. Returns count newly suppressed. */
    public function ingestAvailable(): int
    {
        if (! (bool) config('hearth.deliverability.enabled')) {
            return 0; // dormant
        }

        $newly = 0;

        try {
            $cap = max(1, (int) config('hearth.deliverability.imap.per_tick_cap', 100));
            foreach ($this->mailbox->fetch($cap) as $raw) {
                foreach ($this->parser->parse($raw) as $event) {
                    if ($this->suppressor->applyEvent($event)) {
                        $newly++;
                    }
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
