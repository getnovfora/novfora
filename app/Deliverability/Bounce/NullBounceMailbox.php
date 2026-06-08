<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability\Bounce;

/**
 * Spike P2 — the forced-absence bounce mailbox: no imap extension, or no mailbox configured. Always
 * available (it can't fail) and always empty, so the tri-path manager degrades cleanly to the VERP /
 * manual-ACP floor without ever erroring (GO criterion 4).
 */
final class NullBounceMailbox implements BounceMailbox
{
    public function available(): bool
    {
        return false;
    }

    public function fetch(int $limit): array
    {
        return [];
    }
}
