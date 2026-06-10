<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability\Bounce;

use App\Deliverability\Verp;
use App\Models\User;

/**
 * Spike P2 — clean-room parser for bounce/complaint messages pulled from a bounce mailbox. Reimplemented
 * from the field semantics of RFC 3464 (DSN) and RFC 5965 (ARF) — NO vendor SDK, NO reference-forum code.
 *
 * SECURITY (the load-bearing rule): a bounce mailbox receives mail from the entire internet, so EVERY header
 * is attacker-controlled. The recipient identity is therefore taken ONLY from a cryptographically-signed VERP
 * address (the address the bounce was delivered to — our own envelope sender, HMAC-signed in the local-part).
 * Body recipient headers (Final-Recipient, To, Original-Rcpt-To) are used for CLASSIFICATION ONLY (Status
 * code / ARF type), NEVER as identity — otherwise anyone could email the mailbox a forged DSN and suppress an
 * arbitrary victim (suppression-as-DoS). Consequently, when VERP is not enabled (or no signed address
 * decodes), the polled mailbox auto-suppresses NOTHING — the safe baseline is the webhook (HMAC) or the
 * manual ACP floor; a non-VERP mailbox would need a manual-review queue (a P2-M2 follow-up, out of spike scope).
 *
 * TOTAL: any unparseable / hostile / truncated message yields an empty result — it never throws and a
 * transient 4.x.x bounce is parsed but NEVER suppressed (it self-heals).
 */
final class BounceParser
{
    /** Headers a bounce can be delivered to — where our signed VERP address legitimately appears. */
    private const RECIPIENT_HEADERS = ['Delivered-To', 'X-Original-To', 'Envelope-To', 'Return-Path', 'To'];

    /** Body/report headers that name the FAILED recipient — UNAUTHENTICATED, only used for the review queue. */
    private const CANDIDATE_HEADERS = ['Final-Recipient', 'Original-Rcpt-To', 'Original-Recipient', 'X-Failed-Recipients', 'To'];

    /** Cap on the staff-review excerpt stored from an untrusted message. */
    private const EXCERPT_BYTES = 1500;

    public function __construct(private readonly Verp $verp) {}

    /** @return list<BounceEvent> */
    public function parse(string $raw): array
    {
        try {
            return $this->parseInner($raw);
        } catch (\Throwable) {
            return []; // total: never throw, never suppress on garbage
        }
    }

    /** @return list<BounceEvent> */
    private function parseInner(string $raw): array
    {
        if ($raw === '' || strlen($raw) > 1_048_576) { // 1 MB sanity cap
            return [];
        }

        $text = str_replace(["\r\n", "\r"], "\n", $raw);

        // IDENTITY comes only from a verified VERP address. No signed VERP → nothing to safely suppress.
        $email = $this->verpRecipient($text);
        if ($email === null) {
            return [];
        }

        // CLASSIFICATION comes from the body — but it can only ever classify the already-authenticated address.
        if ($this->looksLikeArf($text)) {
            return [BounceEvent::complaint($email)];
        }

        if (preg_match('/^Status:\s*([245])\.\d+\.\d+/mi', $text, $status) === 1) {
            // 5.x.x permanent → suppress; 4.x.x transient → parsed but NOT suppressed (BounceEvent decides).
            return [BounceEvent::bounce($email, $status[1] === '5')];
        }

        // A bounce delivered to our signed VERP address with no parseable status → treat as a permanent bounce.
        return [BounceEvent::bounce($email, true)];
    }

    /**
     * The recipient email resolved from a cryptographically-signed VERP address in any delivery header.
     * Returns null when VERP is disabled or no header value carries a validly-signed VERP address.
     */
    private function verpRecipient(string $text): ?string
    {
        foreach (self::RECIPIENT_HEADERS as $header) {
            if (preg_match_all('/^'.$header.':\s*(.+)$/mi', $text, $matches) === false) {
                continue;
            }
            foreach ($matches[1] as $line) {
                foreach ($this->addressesIn($line) as $candidate) {
                    $decoded = $this->verp->decode($candidate); // null unless the HMAC verifies
                    if ($decoded === null) {
                        continue;
                    }
                    $email = User::query()->whereKey($decoded['user_id'])->value('email');
                    if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                        return strtolower($email);
                    }
                }
            }
        }

        return null;
    }

    /**
     * NON-VERP manual-review candidate (spike-p2-memo §2b / §8). When VERP is NOT enabled, {@see parse()}
     * auto-suppresses nothing (it can't authenticate a sender-supplied address). Instead, a permanent-bounce /
     * complaint message is surfaced UNVERIFIED for staff to review by hand. Returns null when VERP IS enabled
     * (a bounce to a non-/forged-VERP address is a forgery attempt — queuing it would just re-open the
     * suppression-as-DoS hole as a review-queue flood), when the message isn't a permanent bounce / complaint
     * (a transient 4.x.x self-heals — never queued), or when no candidate recipient can be read. TOTAL: any
     * garbage yields null, never throws.
     *
     * @return array{email:string, type:string, permanent:bool, excerpt:string}|null
     */
    public function reviewCandidate(string $raw): ?array
    {
        try {
            if ($this->verp->enabled() || $raw === '' || strlen($raw) > 1_048_576) {
                return null; // VERP authenticates the mailbox path; nothing to review by hand
            }

            $text = str_replace(["\r\n", "\r"], "\n", $raw);

            // Only queue what staff could act on: a complaint, or a PERMANENT (5.x.x) bounce. Transient skips.
            if ($this->looksLikeArf($text)) {
                $type = BounceEvent::COMPLAINT;
            } elseif (preg_match('/^Status:\s*5\.\d+\.\d+/mi', $text) === 1) {
                $type = BounceEvent::BOUNCE;
            } else {
                return null;
            }

            $email = $this->candidateRecipient($text);
            if ($email === null) {
                return null; // nothing for staff to suppress
            }

            return [
                'email' => $email,
                'type' => $type,
                'permanent' => true,
                'excerpt' => $this->excerpt($text),
            ];
        } catch (\Throwable) {
            return null; // total
        }
    }

    /** First validly-shaped email from an UNAUTHENTICATED body recipient header (staff-review only). */
    private function candidateRecipient(string $text): ?string
    {
        foreach (self::CANDIDATE_HEADERS as $header) {
            if (preg_match_all('/^'.$header.':\s*(.+)$/mi', $text, $matches) === false) {
                continue;
            }
            foreach ($matches[1] as $line) {
                foreach ($this->addressesIn($line) as $candidate) {
                    $email = strtolower(trim($candidate));
                    if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                        return $email;
                    }
                }
            }
        }

        return null;
    }

    /** Bounded, control-stripped snippet of an untrusted message for the ACP review row. */
    private function excerpt(string $text): string
    {
        $snippet = substr($text, 0, self::EXCERPT_BYTES);

        return trim((string) preg_replace('/[^\P{C}\n]+/u', '', $snippet)); // drop control chars, keep newlines
    }

    private function looksLikeArf(string $text): bool
    {
        return stripos($text, 'report-type=feedback-report') !== false
            || stripos($text, 'message/feedback-report') !== false
            || preg_match('/^Feedback-Type:\s*abuse/mi', $text) === 1;
    }

    /**
     * Extract bare email-shaped tokens from a header value (handles `<addr>`, `Name <addr>`, comma lists).
     *
     * @return list<string>
     */
    private function addressesIn(string $line): array
    {
        return preg_match_all('/[^\s<>,;:]+@[^\s<>,;:]+/', $line, $m) ? $m[0] : [];
    }
}
