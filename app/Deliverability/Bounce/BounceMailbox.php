<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability\Bounce;

/**
 * Spike P2 — a source of raw bounce/complaint messages for the daemon-free poll path. Implemented by
 * {@see ImapBounceMailbox} (cron-polled, guarded by the imap extension) and {@see NullBounceMailbox} (the
 * forced-absence default). Every implementation is best-effort: fetch() returns whatever it can and NEVER
 * throws, so a misconfigured / unreachable mailbox degrades to "nothing to ingest" rather than an error.
 */
interface BounceMailbox
{
    /** Is this mailbox usable right now (extension present + configured + reachable enough to try)? */
    public function available(): bool;

    /**
     * Fetch up to $limit raw RFC822 messages and mark them processed (so the next tick won't re-read them;
     * re-reading is harmless anyway — suppression is idempotent). Returns [] when unavailable. Never throws.
     *
     * @return list<string>
     */
    public function fetch(int $limit): array;
}
