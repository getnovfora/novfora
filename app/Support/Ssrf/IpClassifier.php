<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Ssrf;

use App\Content\Oembed\SsrfGuard;
use App\Webhooks\WebhookUrlGuard;

/**
 * The single source of truth for "is this IP unsafe to connect to" — the SSRF deny-list. Both the oEmbed
 * metadata fetcher ({@see SsrfGuard}) and the outbound-webhook guard
 * ({@see WebhookUrlGuard}) classify resolved addresses through THIS class, so the dangerous
 * range logic is defined exactly once and can never drift between the two egress surfaces.
 *
 * Combines PHP's private+reserved rejection with explicit binary checks for the high-value gaps those flags
 * miss (CGNAT 100.64/10, IPv4-mapped-IPv6 private, IPv6 ULA/link-local, and the 6to4/NAT64 transition
 * prefixes that tunnel an IPv4). Anything that is not a parseable, genuinely-public address is blocked
 * (fail-closed).
 */
final class IpClassifier
{
    /** True when $ip must NOT be connected to (private / loopback / link-local / reserved / CGNAT / …). */
    public static function isBlocked(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true; // not an IP at all → block
        }

        // Built-in: rejects 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16 (incl the cloud-metadata
        // 169.254.169.254), 0/8, 224/4, 240/4, fc00::/7, ::1, … by returning false for those.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        // IPv4 (4 bytes) — explicit ranges the flags don't all cover (e.g. CGNAT 100.64/10).
        if (strlen($packed) === 4) {
            return self::ipv4Blocked($packed);
        }

        // IPv6 (16 bytes).
        if (strlen($packed) === 16) {
            $b0 = ord($packed[0]);
            $b1 = ord($packed[1]);

            // IPv4-MAPPED (::ffff:a.b.c.d) — re-check the embedded IPv4 (a private v4 must not slip through v6).
            if (str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
                return self::ipv4Blocked(substr($packed, 12, 4));
            }
            // ::/96 — :: (unspecified), ::1 (loopback) and the deprecated IPv4-COMPATIBLE ::a.b.c.d, all
            // blocked wholesale (no legitimate public host lives here, and ::127.0.0.1 reaches loopback).
            if (str_starts_with($packed, str_repeat("\x00", 12))) {
                return true;
            }
            // 6to4 (2002::/16) and NAT64 (64:ff9b::/96): transition prefixes that TUNNEL an IPv4 (e.g.
            // 2002:7f00:0001::1 → 127.0.0.1, 64:ff9b::a.b.c.d → a.b.c.d) — classic SSRF bypasses, blocked
            // wholesale (no legitimate provider uses them).
            if ($b0 === 0x20 && $b1 === 0x02) {
                return true; // 2002::/16
            }
            if ($b0 === 0x00 && $b1 === 0x64 && ord($packed[2]) === 0xFF && ord($packed[3]) === 0x9B) {
                return true; // 64:ff9b::/96
            }
            // fc00::/7 unique-local
            if (($b0 & 0xFE) === 0xFC) {
                return true;
            }
            // fe80::/10 link-local
            if ($b0 === 0xFE && ($b1 & 0xC0) === 0x80) {
                return true;
            }

            return false;
        }

        return true;
    }

    /** @param string $packed 4 raw bytes */
    private static function ipv4Blocked(string $packed): bool
    {
        $o = array_values(unpack('C4', $packed) ?: [0, 0, 0, 0]);
        [$a, $b] = [$o[0], $o[1]];

        return $a === 0                                   // 0.0.0.0/8
            || $a === 10                                  // 10/8 private
            || $a === 127                                 // 127/8 loopback
            || ($a === 100 && $b >= 64 && $b <= 127)      // 100.64/10 CGNAT
            || ($a === 169 && $b === 254)                 // 169.254/16 link-local (cloud metadata)
            || ($a === 172 && $b >= 16 && $b <= 31)       // 172.16/12 private
            || ($a === 192 && $b === 168)                 // 192.168/16 private
            || $a >= 224;                                 // 224/4 multicast + 240/4 reserved
    }
}
