<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Net;

/**
 * Clean-room CIDR / range matching for IPv4 and IPv6 (U13 / NOV-111, ADR-0121). PHP has no built-in
 * CIDR-contains helper; this is the one authority for it, used by the ip/range ban enforcement. It is:
 *
 *  - **Total** — never throws; malformed input (a bad IP, a bad CIDR, a cross-family compare, an out-of-range
 *    prefix) returns false, so a garbage ban row can never accidentally match every address or crash a hot path.
 *  - **Family-strict** — an IPv4 address never matches an IPv6 CIDR and vice versa (they're incomparable byte
 *    lengths after `inet_pton`), the mistake that would otherwise let a `::/0` ban an IPv4 host.
 *  - **Bit-exact** — compares exactly the first `prefix` bits (byte-aligned full bytes + a masked remainder
 *    byte), matching the `/64`-bucketing precedent in AppServiceProvider.
 */
final class CidrMatcher
{
    /**
     * Does $ip fall inside $cidr? $cidr may be a bare address (treated as a /32 or /128 exact match) or
     * `address/prefix`. Returns false on any malformed/incomparable input.
     */
    public static function matches(string $ip, string $cidr): bool
    {
        $ipBin = @inet_pton(trim($ip));
        if ($ipBin === false) {
            return false;
        }

        [$net, $prefix] = self::splitCidr(trim($cidr), strlen($ipBin) * 8);
        if ($net === null) {
            return false;
        }

        $netBin = @inet_pton($net);
        // Family-strict: both must be the same byte length (4 = IPv4, 16 = IPv6).
        if ($netBin === false || strlen($netBin) !== strlen($ipBin)) {
            return false;
        }
        if ($prefix < 0 || $prefix > strlen($ipBin) * 8) {
            return false;
        }
        if ($prefix === 0) {
            return true; // a /0 matches everything of that family (a deliberate, family-scoped catch-all)
        }

        $fullBytes = intdiv($prefix, 8);
        $remBits = $prefix % 8;

        // Full leading bytes must be identical.
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }

        // The partial byte: compare only the top $remBits bits.
        if ($remBits > 0) {
            $mask = 0xFF << (8 - $remBits) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($netBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Does $ip match ANY of the given CIDRs/addresses?
     *
     * @param  iterable<string>  $cidrs
     */
    public static function matchesAny(string $ip, iterable $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::matches($ip, (string) $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a CIDR/address string for storage (an operator-entered ban value). Returns a normalised
     * `address/prefix` string, or null if it isn't a valid IPv4/IPv6 address or CIDR.
     */
    public static function normalize(string $cidr): ?string
    {
        $cidr = trim($cidr);
        $slash = strpos($cidr, '/');
        $addr = $slash === false ? $cidr : substr($cidr, 0, $slash);

        $bin = @inet_pton($addr);
        if ($bin === false) {
            return null;
        }
        $maxPrefix = strlen($bin) * 8;

        if ($slash === false) {
            return $addr.'/'.$maxPrefix;
        }

        $prefixRaw = substr($cidr, $slash + 1);
        if ($prefixRaw === '' || ! ctype_digit($prefixRaw)) {
            return null;
        }
        $prefix = (int) $prefixRaw;

        return $prefix >= 0 && $prefix <= $maxPrefix ? $addr.'/'.$prefix : null;
    }

    /**
     * Split `address/prefix` (or a bare address) into [address, prefix]; prefix defaults to the family max.
     *
     * @return array{0:?string,1:int}
     */
    private static function splitCidr(string $cidr, int $maxPrefix): array
    {
        $slash = strpos($cidr, '/');
        if ($slash === false) {
            return [$cidr === '' ? null : $cidr, $maxPrefix];
        }

        $prefixRaw = substr($cidr, $slash + 1);
        if ($prefixRaw === '' || ! ctype_digit($prefixRaw)) {
            return [null, 0];
        }

        return [substr($cidr, 0, $slash), (int) $prefixRaw];
    }
}
