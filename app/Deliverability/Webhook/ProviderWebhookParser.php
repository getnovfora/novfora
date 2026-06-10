<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability\Webhook;

use App\Deliverability\Bounce\BounceEvent;

/**
 * Spike P2 / P2-M2 — map a verified provider webhook payload to normalised {@see BounceEvent}s (clean-room,
 * from the providers' documented JSON shapes — no SDK). Reference providers: `postmark` (the recommended
 * transactional outsider-email path), `ses` (Amazon SES, incl. the common SNS-wrapped delivery), `mailgun`,
 * and a `generic` shape. A transient/soft bounce maps to permanent=false → never suppressed.
 *
 * TOTAL + conservative: trust is the caller's HMAC over the raw body (never the payload or a provider's own
 * signature), so this only ever MAPS shapes — garbage / an unknown type / a missing recipient yields no event,
 * never throws, never 500s, and on any ambiguity it prefers NOT suppressing (a deliverable address is never
 * wrongly silenced). Returns the events plus an `eventKey` for replay dedupe (the provider's own id when
 * present, else the caller hashes the raw body).
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
            'ses' => $this->ses($payload),
            'mailgun' => $this->mailgun($payload),
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

    /**
     * Amazon SES. Bounce/Complaint notifications, either sent directly or — the common case — wrapped in an
     * SNS envelope {"Type":"Notification","Message":"<the SES JSON as a string>"}. Unwrap the envelope first,
     * then map the SES notification. Permanent bounce → suppress; Transient → never. One event per recipient.
     *
     * @param  array<string,mixed>  $p
     * @return array{events: list<BounceEvent>, eventKey: ?string}
     */
    private function ses(array $p): array
    {
        if (($p['Type'] ?? null) === 'Notification' && is_string($p['Message'] ?? null)) {
            $snsKey = $this->str($p['MessageId'] ?? null);
            $inner = json_decode((string) $p['Message'], true);
            if (! is_array($inner)) {
                return ['events' => [], 'eventKey' => $snsKey];
            }
            $result = $this->sesNotification($inner);

            return ['events' => $result['events'], 'eventKey' => $result['eventKey'] ?? $snsKey];
        }

        return $this->sesNotification($p);
    }

    /**
     * @param  array<string,mixed>  $p
     * @return array{events: list<BounceEvent>, eventKey: ?string}
     */
    private function sesNotification(array $p): array
    {
        $type = (string) ($p['notificationType'] ?? $p['eventType'] ?? '');
        $messageKey = $this->str(is_array($p['mail'] ?? null) ? ($p['mail']['messageId'] ?? null) : null);

        if ($type === 'Bounce') {
            $bounce = is_array($p['bounce'] ?? null) ? $p['bounce'] : [];
            $permanent = (string) ($bounce['bounceType'] ?? '') === 'Permanent';
            $events = $this->sesEvents(
                is_array($bounce['bouncedRecipients'] ?? null) ? $bounce['bouncedRecipients'] : [],
                fn (string $email) => BounceEvent::bounce($email, $permanent),
            );

            return ['events' => $events, 'eventKey' => $this->str($bounce['feedbackId'] ?? null) ?? $messageKey];
        }

        if ($type === 'Complaint') {
            $complaint = is_array($p['complaint'] ?? null) ? $p['complaint'] : [];
            $events = $this->sesEvents(
                is_array($complaint['complainedRecipients'] ?? null) ? $complaint['complainedRecipients'] : [],
                fn (string $email) => BounceEvent::complaint($email),
            );

            return ['events' => $events, 'eventKey' => $this->str($complaint['feedbackId'] ?? null) ?? $messageKey];
        }

        // Delivery / Send / Subscription-confirmation / unknown → nothing to suppress (eventKey aids dedupe).
        return ['events' => [], 'eventKey' => $messageKey];
    }

    /**
     * @param  array<mixed>  $recipients
     * @param  callable(string):BounceEvent  $make
     * @return list<BounceEvent>
     */
    private function sesEvents(array $recipients, callable $make): array
    {
        $events = [];
        foreach ($recipients as $r) {
            $email = $this->email(is_array($r) ? ($r['emailAddress'] ?? null) : null);
            if ($email !== null) {
                $events[] = $make($email);
            }
        }

        return $events;
    }

    /**
     * Mailgun. Modern shape {"event-data":{"event":"failed"|"complained","severity":"permanent"|"temporary",
     * "recipient":…,"id":…}}; falls back to a flat shape. We do NOT verify Mailgun's own signature block — trust
     * is the caller's HMAC over the raw body. 'failed' is suppressed ONLY at severity 'permanent' (ambiguity →
     * transient, never suppressed); 'complained' is a complaint.
     *
     * @param  array<string,mixed>  $p
     * @return array{events: list<BounceEvent>, eventKey: ?string}
     */
    private function mailgun(array $p): array
    {
        $d = is_array($p['event-data'] ?? null) ? $p['event-data'] : $p;
        $event = strtolower((string) ($d['event'] ?? ''));
        $email = $this->email($d['recipient'] ?? null);
        $eventKey = $this->str($d['id'] ?? null)
            ?? $this->str(is_array($p['signature'] ?? null) ? ($p['signature']['token'] ?? null) : null);

        if ($email === null) {
            return ['events' => [], 'eventKey' => $eventKey];
        }

        if ($event === 'complained') {
            return ['events' => [BounceEvent::complaint($email)], 'eventKey' => $eventKey];
        }

        if ($event === 'failed' || $event === 'bounced' || $event === 'rejected') {
            $permanent = strtolower((string) ($d['severity'] ?? '')) === 'permanent';

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
