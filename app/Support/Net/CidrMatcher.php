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
 *  - **Binary-canonical** — every address is compared and stored as its `inet_pton` bytes, so equivalent textual
 *    spellings of one IPv6 address (uppercase, zero-expanded, alternate compression) are one target, not many.
 *    An IPv4-mapped IPv6 address (`::ffff:a.b.c.d`) is folded to its 4-byte IPv4 form so a dual-stack client and
 *    the plain IPv4 ban are the same target.
 *  - **Family-strict** — an IPv4 address never matches an IPv6 CIDR and vice versa (incomparable byte lengths
 *    after `inet_pton`), the mistake that would otherwise let a `::/0` ban an IPv4 host.
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
        $ipBin = self::toBinary($ip);
        if ($ipBin === null) {
            return false;
        }

        [$net, $prefix] = self::splitCidr(trim($cidr));
        if ($net === null) {
            return false;
        }

        $netBin = self::toBinary($net);
        // Family-strict: both must be the same byte length (4 = IPv4, 16 = IPv6).
        if ($netBin === null || strlen($netBin) !== strlen($ipBin)) {
            return false;
        }

        $max = strlen($ipBin) * 8;
        $prefix ??= $max; // a bare address is an exact (/32 or /128) match
        if ($prefix < 0 || $prefix > $max) {
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
     * Parse + canonicalise a ban value (an operator-entered ip / CIDR string) for storage and classification.
     * Returns null if it isn't a valid IPv4/IPv6 address or CIDR. `address` and `cidr` are the CANONICAL forms
     * (folded through inet_pton/inet_ntop), so two spellings of one address store identically; `isHost` is true
     * when the prefix covers a single address (/32 v4, /128 v6), i.e. an exact-ip ban rather than a range.
     *
     * @return array{address:string, prefix:int, max:int, isHost:bool, cidr:string}|null
     */
    public static function parse(string $cidr): ?array
    {
        [$addr, $prefix] = self::splitCidr(trim($cidr));
        if ($addr === null) {
            return null;
        }

        $bin = self::toBinary($addr);
        if ($bin === null) {
            return null;
        }
        $canonical = @inet_ntop($bin);
        if ($canonical === false) {
            return null;
        }

        $max = strlen($bin) * 8;
        $prefix ??= $max; // a bare address is an exact host
        if ($prefix < 0 || $prefix > $max) {
            return null;
        }

        return [
            'address' => $canonical,
            'prefix' => $prefix,
            'max' => $max,
            'isHost' => $prefix === $max,
            'cidr' => $canonical.'/'.$prefix,
        ];
    }

    /**
     * Validate a CIDR/address string for storage. Returns the canonical `address/prefix` string, or null if it
     * isn't a valid IPv4/IPv6 address or CIDR. (Kept for callers that only need the normalised string.)
     */
    public static function normalize(string $cidr): ?string
    {
        $parsed = self::parse($cidr);

        return $parsed === null ? null : $parsed['cidr'];
    }

    /**
     * Canonical textual form of a SINGLE address (no prefix) — folds an IPv4-mapped IPv6 down to IPv4 and
     * collapses equivalent IPv6 spellings to one form. Null if the input is not a valid IP address. Use this to
     * canonicalise a live request IP before an exact-set lookup so it matches the canonically-stored ban value.
     */
    public static function canonicalIp(string $ip): ?string
    {
        $bin = self::toBinary($ip);
        if ($bin === null) {
            return null;
        }
        $out = @inet_ntop($bin);

        return $out === false ? null : $out;
    }

    /**
     * inet_pton the address, then fold an IPv4-mapped IPv6 (`::ffff:a.b.c.d`, 16 bytes) to its 4-byte IPv4 form
     * so it is comparable to a plain IPv4 ban. Null on any invalid address.
     */
    private static function toBinary(string $addr): ?string
    {
        $bin = @inet_pton(trim($addr));
        if ($bin === false) {
            return null;
        }
        // ::ffff:0:0/96 — the IPv4-mapped IPv6 block: the last 4 bytes ARE the IPv4 address.
        if (strlen($bin) === 16 && substr($bin, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            return substr($bin, 12);
        }

        return $bin;
    }

    /**
     * Split `address/prefix` (or a bare address) into [address, prefix]. Prefix is null for a bare address
     * (the caller supplies the family max). A malformed prefix (empty or non-numeric) yields [null, null] so
     * matches()/parse() reject the whole value rather than silently treating it as bare.
     *
     * @return array{0:?string, 1:?int}
     */
    private static function splitCidr(string $cidr): array
    {
        $slash = strpos($cidr, '/');
        if ($slash === false) {
            return [$cidr === '' ? null : $cidr, null];
        }

        $prefixRaw = substr($cidr, $slash + 1);
        if ($prefixRaw === '' || ! ctype_digit($prefixRaw)) {
            return [null, null];
        }

        return [substr($cidr, 0, $slash), (int) $prefixRaw];
    }
}
