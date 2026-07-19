<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\Net\CidrMatcher;

/*
| U13 (NOV-111, ADR-0121) — the CIDR authority. Total (never throws), family-strict (no v4↔v6 cross-match),
| bit-exact on the prefix boundary. Malformed input always returns false (a garbage ban can't match all).
*/

it('matches IPv4 addresses inside and outside a CIDR', function () {
    expect(CidrMatcher::matches('203.0.113.5', '203.0.113.0/24'))->toBeTrue()
        ->and(CidrMatcher::matches('203.0.113.255', '203.0.113.0/24'))->toBeTrue()
        ->and(CidrMatcher::matches('203.0.114.1', '203.0.113.0/24'))->toBeFalse()
        ->and(CidrMatcher::matches('203.0.113.5', '203.0.113.5'))->toBeTrue()   // bare = exact
        ->and(CidrMatcher::matches('203.0.113.6', '203.0.113.5'))->toBeFalse();
});

it('is bit-exact on a non-byte-aligned prefix boundary', function () {
    // /28 = 4 host bits; 203.0.113.16 opens a new /28 block from 203.0.113.0/28.
    expect(CidrMatcher::matches('203.0.113.15', '203.0.113.0/28'))->toBeTrue()
        ->and(CidrMatcher::matches('203.0.113.16', '203.0.113.0/28'))->toBeFalse()
        ->and(CidrMatcher::matches('10.1.2.130', '10.1.2.128/25'))->toBeTrue()   // /25 boundary
        ->and(CidrMatcher::matches('10.1.2.127', '10.1.2.128/25'))->toBeFalse();
});

it('matches IPv6 addresses inside a CIDR', function () {
    expect(CidrMatcher::matches('2001:db8::1', '2001:db8::/32'))->toBeTrue()
        ->and(CidrMatcher::matches('2001:db8:ffff::1', '2001:db8::/32'))->toBeTrue()
        ->and(CidrMatcher::matches('2001:db9::1', '2001:db8::/32'))->toBeFalse()
        ->and(CidrMatcher::matches('2001:db8:1::', '2001:db8::/48'))->toBeFalse();
});

it('is family-strict — v4 never matches a v6 CIDR or vice versa (no ::/0 blanket)', function () {
    expect(CidrMatcher::matches('203.0.113.5', '::/0'))->toBeFalse()        // v4 vs v6 catch-all
        ->and(CidrMatcher::matches('2001:db8::1', '0.0.0.0/0'))->toBeFalse()  // v6 vs v4 catch-all
        ->and(CidrMatcher::matches('203.0.113.5', '0.0.0.0/0'))->toBeTrue()   // v4 vs v4 /0 = all
        ->and(CidrMatcher::matches('2001:db8::1', '::/0'))->toBeTrue();       // v6 vs v6 /0 = all
});

it('returns false (never throws) on every malformed input', function () {
    foreach ([
        ['not-an-ip', '203.0.113.0/24'],
        ['203.0.113.5', 'not-a-cidr'],
        ['203.0.113.5', '203.0.113.0/33'],   // prefix out of range for v4
        ['203.0.113.5', '203.0.113.0/-1'],
        ['203.0.113.5', '203.0.113.0/abc'],
        ['', ''],
        ['203.0.113.5', ''],
        ['2001:db8::1', '2001:db8::/129'],    // prefix out of range for v6
    ] as [$ip, $cidr]) {
        expect(CidrMatcher::matches($ip, $cidr))->toBeFalse();
    }
});

it('normalises valid values and rejects invalid ones for storage', function () {
    expect(CidrMatcher::normalize('203.0.113.5'))->toBe('203.0.113.5/32')
        ->and(CidrMatcher::normalize('203.0.113.0/24'))->toBe('203.0.113.0/24')
        ->and(CidrMatcher::normalize('2001:db8::'))->toBe('2001:db8::/128')
        ->and(CidrMatcher::normalize('2001:db8::/48'))->toBe('2001:db8::/48')
        ->and(CidrMatcher::normalize('nonsense'))->toBeNull()
        ->and(CidrMatcher::normalize('203.0.113.0/40'))->toBeNull();
});

it('parse classifies an IPv6 /32 as a RANGE, not a single host (the U13 apex HIGH)', function () {
    // str_ends_with(..., "/32") wrongly treated an IPv6 /32 as exact; parse() decides by the parsed prefix.
    expect(CidrMatcher::parse('2001:db8::/32')['isHost'])->toBeFalse()
        ->and(CidrMatcher::parse('2001:db8::/32')['cidr'])->toBe('2001:db8::/32')
        ->and(CidrMatcher::parse('2001:db8::1')['isHost'])->toBeTrue()   // /128 = exact host
        ->and(CidrMatcher::parse('203.0.113.0/24')['isHost'])->toBeFalse()
        ->and(CidrMatcher::parse('203.0.113.5')['isHost'])->toBeTrue()   // /32 = exact host
        ->and(CidrMatcher::parse('0.0.0.0/0')['prefix'])->toBe(0)
        ->and(CidrMatcher::parse('nonsense'))->toBeNull();
});

it('canonicalises equivalent IPv6 spellings and folds IPv4-mapped IPv6 to IPv4 (the U13 apex MEDIUM/LOW)', function () {
    // One address, many spellings → one canonical form (so an exact-set lookup can never miss it).
    expect(CidrMatcher::canonicalIp('2001:DB8::1'))->toBe('2001:db8::1')
        ->and(CidrMatcher::canonicalIp('2001:0db8:0000:0000:0000:0000:0000:0001'))->toBe('2001:db8::1')
        ->and(CidrMatcher::parse('2001:DB8::1')['address'])->toBe('2001:db8::1')
        // IPv4-mapped IPv6 folds to the plain IPv4 form on BOTH the match and the canonical paths.
        ->and(CidrMatcher::canonicalIp('::ffff:203.0.113.5'))->toBe('203.0.113.5')
        ->and(CidrMatcher::matches('::ffff:203.0.113.5', '203.0.113.0/24'))->toBeTrue()
        ->and(CidrMatcher::matches('::ffff:203.0.113.5', '203.0.113.5'))->toBeTrue()
        ->and(CidrMatcher::canonicalIp('not-an-ip'))->toBeNull();
});

it('fuzz: a random /24 contains exactly its 256 addresses and nothing adjacent', function () {
    mt_srand(424242);
    for ($r = 0; $r < 40; $r++) {
        $a = mt_rand(1, 223);
        $b = mt_rand(0, 255);
        $c = mt_rand(0, 255);
        $cidr = "{$a}.{$b}.{$c}.0/24";
        // Every host in the block matches.
        foreach ([0, 1, 128, 254, 255] as $host) {
            expect(CidrMatcher::matches("{$a}.{$b}.{$c}.{$host}", $cidr))->toBeTrue();
        }
        // The adjacent /24 (c+1, wrapping) does not.
        $c2 = ($c + 1) % 256;
        if ($c2 !== $c) {
            expect(CidrMatcher::matches("{$a}.{$b}.{$c2}.0", $cidr))->toBeFalse();
        }
    }
});
