<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability\Webhook;

use App\Deliverability\Bounce\BounceEvent;

/**
 * Spike P2 — map a verified provider webhook payload to normalised {@see BounceEvent}s (clean-room, from the
 * providers' documented JSON shapes — no SDK). Two reference providers: `postmark` (the recommended
 * transactional outsider-email path, confirmed per the kickoff) and a `generic` shape for any other
 * provider / the reference tests. A transient/soft bounce maps to permanent=false → never suppressed.
 *
 * Returns the events plus an `eventKey` for replay dedupe (the provider's own id when present, else the
 * caller hashes the raw body).
 */
final class ProviderWebhookParser
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array{events: list<BounceEvent>, eventKey: ?string}
     */
    public function parse(string $provider, array $payload): array
    {
        return match (strtolower($provider)) {
            'postmark' => $this->postmark($payload),
            'generic' => $this->generic($payload),
            default => ['events' => [], 'eventKey' => null],
        };
    }

    /**
     * @param  array<string,mixed>  $p
     * @return array{events: list<BounceEvent>, eventKey: ?string}
     */
    private function postmark(array $p): array
    {
        $record = (string) ($p['RecordType'] ?? '');
        $email = $this->email($p['Email'] ?? null);
        $eventKey = isset($p['ID']) ? 'postmark:'.$p['ID'] : ($this->str($p['MessageID'] ?? null));

        if ($email === null) {
            return ['events' => [], 'eventKey' => $eventKey];
        }

        if ($record === 'SpamComplaint') {
            return ['events' => [BounceEvent::complaint($email)], 'eventKey' => $eventKey];
        }

        if ($record === 'Bounce') {
            // Postmark's hard-failure types. Anything else (Transient, SoftBounce, DnsError, …) = transient.
            $hard = ['HardBounce', 'BadEmailAddress', 'SpamComplaint', 'ManuallyDeactivated', 'Unsubscribe', 'Blocked'];
            $permanent = in_array((string) ($p['Type'] ?? ''), $hard, true);

            return ['events' => [BounceEvent::bounce($email, $permanent)], 'eventKey' => $eventKey];
        }

        return ['events' => [], 'eventKey' => $eventKey];
    }

    /**
     * Reference/generic shape: {"type":"bounce"|"complaint","email":"…","permanent":true,"id":"…"}.
     *
     * @param  array<string,mixed>  $p
     * @return array{events: list<BounceEvent>, eventKey: ?string}
     */
    private function generic(array $p): array
    {
        $email = $this->email($p['email'] ?? null);
        $eventKey = $this->str($p['id'] ?? null);
        if ($email === null) {
            return ['events' => [], 'eventKey' => $eventKey];
        }

        $type = (string) ($p['type'] ?? '');
        if ($type === BounceEvent::COMPLAINT) {
            return ['events' => [BounceEvent::complaint($email)], 'eventKey' => $eventKey];
        }
        if ($type === BounceEvent::BOUNCE) {
            $permanent = filter_var($p['permanent'] ?? true, FILTER_VALIDATE_BOOLEAN);

            return ['events' => [BounceEvent::bounce($email, $permanent)], 'eventKey' => $eventKey];
        }

        return ['events' => [], 'eventKey' => $eventKey];
    }

    private function email(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
