<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Content\Oembed\SsrfException;
use App\Content\Oembed\SsrfGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
| SSRF battery (P2-M1, security inventory §3) — PERMANENT. A server-side fetch of a user-influenced URL must
| never reach a private/internal address, must re-validate every redirect hop, must cap size, and must fail
| closed (→ facade) on any error. The resolver is injected so DNS behaviour is deterministic.
*/

// ── IP classification ───────────────────────────────────────────────────────────────────────────
it('blocks private / loopback / link-local / reserved / CGNAT / IPv4-mapped addresses', function (string $ip) {
    expect((new SsrfGuard)->isBlockedIp($ip))->toBeTrue();
})->with([
    '127.0.0.1', '10.0.0.1', '172.16.5.4', '172.31.255.255', '192.168.1.1', '169.254.169.254', // metadata
    '100.64.0.1', '100.127.0.1', '0.0.0.0', '::1', '::', 'fe80::1', 'fc00::1', 'fd00::1',
    '::ffff:127.0.0.1', '::ffff:10.0.0.1', '224.0.0.1', '240.0.0.1', 'totally-not-an-ip',
    // IPv6 transition/encoding bypasses that tunnel an IPv4:
    '2002:7f00:0001::1',  // 6to4 → 127.0.0.1
    '2002:0a00:0001::',   // 6to4 → 10.0.0.1
    '64:ff9b::c0a8:0101',  // NAT64 → 192.168.1.1
    '::127.0.0.1',         // IPv4-compatible → loopback
    '::0a00:0001',         // IPv4-compatible → 10.0.0.1
]);

it('allows genuine public addresses', function (string $ip) {
    expect((new SsrfGuard)->isBlockedIp($ip))->toBeFalse();
})->with(['8.8.8.8', '1.1.1.1', '93.184.216.34', '172.15.0.1', '172.32.0.1', '2606:4700:4700::1111']);

// ── validate() ──────────────────────────────────────────────────────────────────────────────────
it('rejects a non-https scheme', function () {
    (new SsrfGuard)->validate('http://example.com/');
})->throws(SsrfException::class);

it('rejects an IP-literal host that is private', function () {
    (new SsrfGuard)->validate('https://127.0.0.1/oembed');
})->throws(SsrfException::class);

it('rejects a host that RESOLVES to a private IP (DNS-based SSRF)', function () {
    (new SsrfGuard(fn () => ['10.0.0.5']))->validate('https://evil.example/');
})->throws(SsrfException::class);

it('rejects when ANY resolved address is private (mixed records)', function () {
    (new SsrfGuard(fn () => ['8.8.8.8', '127.0.0.1']))->validate('https://mixed.example/');
})->throws(SsrfException::class);

it('accepts an https host resolving only to public IPs', function () {
    expect((new SsrfGuard(fn () => ['8.8.8.8']))->validate('https://example.com/')['host'])->toBe('example.com');
});

// ── safeGet() ───────────────────────────────────────────────────────────────────────────────────
it('safeGet fails closed for a host resolving to a private IP, sending nothing', function () {
    Http::fake();
    expect((new SsrfGuard(fn () => ['10.0.0.5']))->safeGet('https://evil.example/'))->toBeNull();
    Http::assertNothingSent();
});

it('safeGet blocks a redirect to an internal address (re-validates each hop)', function () {
    Http::fake([
        'https://public.example/*' => Http::response('', 302, ['Location' => 'https://internal.example/secret']),
        'https://internal.example/*' => Http::response('LEAKED', 200),
    ]);
    $guard = new SsrfGuard(fn (string $host): array => $host === 'internal.example' ? ['127.0.0.1'] : ['8.8.8.8']);

    expect($guard->safeGet('https://public.example/start'))->toBeNull();
});

it('safeGet refuses an oversize response', function () {
    Http::fake(['*' => Http::response(str_repeat('x', 300_000), 200)]);

    expect((new SsrfGuard(fn () => ['8.8.8.8']))->safeGet('https://example.com/', ['max_bytes' => 262144]))->toBeNull();
});

it('safeGet returns null on a connection failure (timeout)', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect((new SsrfGuard(fn () => ['8.8.8.8']))->safeGet('https://example.com/'))->toBeNull();
});

it('safeGet returns the body on a clean public fetch', function () {
    Http::fake(['*' => Http::response('{"title":"ok"}', 200)]);

    expect((new SsrfGuard(fn () => ['8.8.8.8']))->safeGet('https://example.com/'))->toBe('{"title":"ok"}');
});
