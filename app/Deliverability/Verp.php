<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability;

/**
 * Spike P2 — VERP (Variable Envelope Return Path). Embeds the recipient + send id in the envelope sender
 * (Return-Path), so a bounce identifies the address with NO body parsing — the always-available floor.
 *
 * Forgery-safe: the local-part carries a truncated HMAC over "{userId}.{sendId}" under a per-install key, so
 * a hand-crafted `bounce+…@domain` cannot suppress a victim — {@see decode()} recomputes the signature with
 * a constant-time compare and returns null on any mismatch/garbage. Distinct from the on-domain `From`
 * (which must stay on-domain for SPF/DKIM alignment); VERP only sets the Return-Path / envelope sender.
 *
 * Local-part shape: `bounce+{userId}.{sendId}.{sig}` (digits + dots — all valid local-part chars).
 */
final class Verp
{
    private const PREFIX = 'bounce+';

    public function enabled(): bool
    {
        return (bool) config('hearth.deliverability.verp.enabled')
            && $this->domain() !== ''
            && $this->key() !== '';
    }

    /** The signed Return-Path address for a send, or null when VERP is not configured. */
    public function returnPathFor(int $userId, int $sendId): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $sig = $this->sign($userId, $sendId);

        return self::PREFIX."{$userId}.{$sendId}.{$sig}@".$this->domain();
    }

    /**
     * Decode + VERIFY a Return-Path / envelope address. Returns ['user_id'=>int,'send_id'=>int] when the
     * signature checks out, else null (forged, malformed, or not a VERP address). Never throws.
     *
     * @return array{user_id:int, send_id:int}|null
     */
    public function decode(string $address): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $local = strtolower(trim(explode('@', $address, 2)[0] ?? ''));
        if (! str_starts_with($local, self::PREFIX)) {
            return null;
        }

        $parts = explode('.', substr($local, strlen(self::PREFIX)));
        if (count($parts) !== 3) {
            return null;
        }

        [$userPart, $sendPart, $sig] = $parts;
        if (! ctype_digit($userPart) || ! ctype_digit($sendPart)) {
            return null;
        }

        $userId = (int) $userPart;
        $sendId = (int) $sendPart;

        if (! hash_equals($this->sign($userId, $sendId), $sig)) {
            return null; // forged or tampered — do NOT suppress
        }

        return ['user_id' => $userId, 'send_id' => $sendId];
    }

    private function sign(int $userId, int $sendId): string
    {
        return substr(hash_hmac('sha256', "{$userId}.{$sendId}", $this->key()), 0, 16);
    }

    private function domain(): string
    {
        return strtolower(trim((string) config('hearth.deliverability.verp.domain', '')));
    }

    private function key(): string
    {
        return (string) config('hearth.deliverability.verp.key', '');
    }
}
