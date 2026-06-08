<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability\Bounce;

/**
 * Spike P2 — the cron-polled IMAP bounce mailbox (the second of the three ingestion paths). Guarded by the
 * `imap` PHP extension; when it is absent the binding is {@see NullBounceMailbox}, so the path degrades to
 * a no-op (the manager never instantiates this without the extension). Best-effort throughout: every imap_*
 * call is wrapped, the connection is always closed, and any failure yields the messages gathered so far (or
 * none) — it never throws and never blocks the cron tick. Raw RFC822 messages are handed to {@see BounceParser}.
 *
 * Reference-grade: it cannot run in this build environment (no imap extension) and is exercised in tests via
 * an in-memory {@see BounceMailbox}; the parser + suppression + degrade contract are what the GO tests pin.
 */
final class ImapBounceMailbox implements BounceMailbox
{
    public function available(): bool
    {
        return extension_loaded('imap')
            && (bool) config('hearth.deliverability.imap.enabled')
            && (string) config('hearth.deliverability.imap.host', '') !== ''
            && (string) config('hearth.deliverability.imap.username', '') !== '';
    }

    public function fetch(int $limit): array
    {
        if (! $this->available() || $limit <= 0) {
            return [];
        }

        $messages = [];
        $connection = null;

        try {
            $connection = @imap_open($this->mailboxString(), $this->username(), $this->password(), 0, 1);
            if ($connection === false) {
                return [];
            }

            $count = @imap_num_msg($connection);
            $count = is_int($count) ? $count : 0;
            $take = min($limit, $count);
            $delete = (bool) config('hearth.deliverability.imap.delete_processed', true);

            for ($i = 1; $i <= $take; $i++) {
                // FT_PEEK: don't flip \Seen — we decide deletion explicitly below.
                $header = @imap_fetchheader($connection, $i);
                $body = @imap_body($connection, $i, FT_PEEK);
                if (is_string($header) && is_string($body)) {
                    $messages[] = $header.$body;
                }
                if ($delete) {
                    @imap_delete($connection, $i);
                }
            }

            if ($delete) {
                @imap_expunge($connection);
            }
        } catch (\Throwable) {
            // best-effort — return whatever was gathered before the failure
        } finally {
            if ($connection !== false && $connection !== null) {
                @imap_close($connection);
            }
        }

        return $messages;
    }

    private function mailboxString(): string
    {
        $host = (string) config('hearth.deliverability.imap.host');
        $port = (int) config('hearth.deliverability.imap.port', 993);
        $mailbox = (string) config('hearth.deliverability.imap.mailbox', 'INBOX');

        $flags = match ((string) config('hearth.deliverability.imap.encryption', 'ssl')) {
            'tls' => '/imap/tls',
            'none' => '/imap/notls',
            default => '/imap/ssl',
        };

        return "{{$host}:{$port}{$flags}}{$mailbox}";
    }

    private function username(): string
    {
        return (string) config('hearth.deliverability.imap.username', '');
    }

    private function password(): string
    {
        return (string) config('hearth.deliverability.imap.password', '');
    }
}
