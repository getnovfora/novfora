<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Providers;

use App\Deliverability\Bounce\BounceMailbox;
use App\Deliverability\Bounce\ImapBounceMailbox;
use App\Deliverability\Bounce\NullBounceMailbox;
use Illuminate\Support\ServiceProvider;

/**
 * Spike P2 — wires the deliverability reference pipeline. The only binding that needs a decision is the
 * bounce mailbox: the real {@see ImapBounceMailbox} when the imap extension is present AND a mailbox is
 * configured, otherwise the {@see NullBounceMailbox} forced-absence default. Everything else autowires.
 * Nothing here activates the pipeline — the cron lines and routes self-gate on hearth.deliverability.enabled.
 */
final class DeliverabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BounceMailbox::class, function () {
            $imap = (bool) config('hearth.deliverability.imap.enabled')
                && extension_loaded('imap')
                && (string) config('hearth.deliverability.imap.host', '') !== '';

            return $imap ? new ImapBounceMailbox : new NullBounceMailbox;
        });
    }
}
