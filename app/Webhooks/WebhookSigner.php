<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Webhooks;

/**
 * Signs an outbound webhook body (ADR-0033). Uses the SAME canonical string + headers as the inbound
 * App\Deliverability\Webhook\WebhookVerifier — `HMAC-SHA256("{timestamp}.{body}", secret)` — so a receiver can
 * verify a NovFora-sent webhook with identical logic (and reject replays via the timestamp).
 */
final class WebhookSigner
{
    public const SIGNATURE_HEADER = 'X-NovFora-Signature';

    public const TIMESTAMP_HEADER = 'X-NovFora-Timestamp';

    /** @return array<string,string> the signature + timestamp headers to send with the body */
    public function headers(string $body, string $secret): array
    {
        $timestamp = (string) now()->getTimestamp();

        return [
            self::SIGNATURE_HEADER => hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
            self::TIMESTAMP_HEADER => $timestamp,
        ];
    }
}
