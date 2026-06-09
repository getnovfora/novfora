<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Content\Oembed;

use Illuminate\Support\Facades\Http;

/**
 * SSRF guard for outbound oEmbed metadata fetches (P2-M1, security inventory §3). A server-side fetch of a
 * user-influenced URL is an SSRF surface; this guard makes it safe:
 *
 *   • https only — no http/file/gopher/etc.
 *   • resolve EVERY A/AAAA record for the host and block the request if ANY resolved address is
 *     private / loopback / link-local / reserved / CGNAT / IPv4-mapped-private (RFC-1918/5156/…).
 *   • re-validate after EVERY redirect (a 30x to an internal URL is the classic bypass) and cap the redirects.
 *   • pin host → a validated IP for the actual connection (CURLOPT_RESOLVE), closing the resolve-vs-connect
 *     DNS-rebinding gap.
 *   • bounded connect/total timeout + a response-size cap.
 *
 * The resolver is injectable so the guard is deterministically testable without real DNS.
 */
final class SsrfGuard
{
    /** @var callable(string): list<string> resolves a hostname to a list of IP strings (A + AAAA) */
    private $resolver;

    /** @param (callable(string): list<string>)|null $resolver */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? fn (string $host): array => self::systemResolve($host);
    }

    /**
     * Validate a URL is safe to fetch and return the host + its validated public IPs.
     *
     * @return array{host:string, ips:list<string>}
     *
     * @throws SsrfException
     */
    public function validate(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new SsrfException('Unparseable URL.');
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new SsrfException('Only https URLs may be fetched.');
        }

        $host = (string) $parts['host'];
        // Strip the brackets of an IPv6 literal host ONLY when properly paired (a malformed "[::1" stays as-is
        // and fails IP/resolve validation rather than being silently rewritten).
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '') {
            throw new SsrfException('Missing host.');
        }

        // If the host IS an IP literal, validate it directly (no DNS). Otherwise resolve every record.
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            throw new SsrfException("Host {$host} does not resolve.");
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new SsrfException("Host {$host} resolves to a blocked address ({$ip}).");
            }
        }

        return ['host' => $host, 'ips' => $ips];
    }

    /**
     * Fetch a URL safely: validate, follow up to max_redirects redirects (re-validating each hop), pin the
     * connection to a validated IP, and cap the response size. Returns the body, or null on ANY failure
     * (SSRF block, timeout, oversize, bad status) — the caller degrades to a facade.
     *
     * @param  array<string,mixed>  $cfg  oembed config (timeout, connect_timeout, max_redirects, max_bytes)
     */
    public function safeGet(string $url, array $cfg = []): ?string
    {
        $timeout = (int) ($cfg['timeout'] ?? 5);
        $connectTimeout = (int) ($cfg['connect_timeout'] ?? 3);
        $maxRedirects = (int) ($cfg['max_redirects'] ?? 3);
        $maxBytes = (int) ($cfg['max_bytes'] ?? 262144);

        $current = $url;
        try {
            for ($hop = 0; $hop <= $maxRedirects; $hop++) {
                // Re-validate EVERY hop (the initial URL and every redirect target).
                $validated = $this->validate($current);

                $response = Http::withHeaders(['Accept' => 'application/json, text/html;q=0.5'])
                    ->withOptions([
                        'allow_redirects' => false,          // we follow manually so we can re-validate each hop
                        'connect_timeout' => $connectTimeout,
                        // Pin host → a validated IP so the actual TCP connection cannot be DNS-rebound to an
                        // internal address between validation and connect. (Ignored by Http::fake in tests.)
                        'curl' => [CURLOPT_RESOLVE => $this->resolvePins($validated, $current)],
                    ])
                    ->timeout($timeout)
                    ->get($current);

                $status = $response->status();

                if ($status >= 300 && $status < 400) {
                    $location = $response->header('Location');
                    // Reject a missing or CRLF-bearing Location (response-splitting / log-injection hygiene)
                    // before it is logged or absolutised.
                    if ($location === '' || strpbrk($location, "\r\n") !== false) {
                        return null;
                    }
                    $current = $this->absolutize($location, $current);

                    continue;
                }

                if (! $response->successful()) {
                    return null;
                }

                $body = (string) $response->body();
                // Size cap: refuse an oversize payload (also guards a lying/absent Content-Length).
                if (strlen($body) > $maxBytes) {
                    return null;
                }
                $contentLength = (int) $response->header('Content-Length');
                if ($contentLength > $maxBytes) {
                    return null;
                }

                return $body;
            }
        } catch (SsrfException) {
            return null; // a blocked hop degrades to a facade, never an error
        } catch (\Throwable) {
            return null; // timeout / connection / parse failure → facade
        }

        return null; // too many redirects
    }

    /**
     * Classify an IP as unsafe to connect to. Combines PHP's private+reserved rejection with explicit binary
     * checks for the high-value gaps those flags miss (CGNAT, IPv4-mapped-IPv6 private, IPv6 ULA/link-local).
     */
    public function isBlockedIp(string $ip): bool
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
            return $this->ipv4Blocked($packed);
        }

        // IPv6 (16 bytes).
        if (strlen($packed) === 16) {
            $b0 = ord($packed[0]);
            $b1 = ord($packed[1]);

            // IPv4-MAPPED (::ffff:a.b.c.d) — re-check the embedded IPv4 (a private v4 must not slip through v6).
            if (str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
                return $this->ipv4Blocked(substr($packed, 12, 4));
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
    private function ipv4Blocked(string $packed): bool
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

    /**
     * Build the CURLOPT_RESOLVE pin list (host:port:ip) from the validated IPs, for both ports.
     *
     * @param  array{host:string, ips:list<string>}  $validated
     * @return list<string>
     */
    private function resolvePins(array $validated, string $url): array
    {
        $port = parse_url($url, PHP_URL_PORT) ?: 443;
        $pins = [];
        foreach ($validated['ips'] as $ip) {
            $pins[] = "{$validated['host']}:{$port}:{$ip}";
        }

        return $pins;
    }

    /** Resolve a relative/absolute Location against the current URL. */
    private function absolutize(string $location, string $base): string
    {
        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        if ($b === false || ! isset($b['scheme'], $b['host'])) {
            return $location;
        }
        $origin = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');

        return str_starts_with($location, '/')
            ? $origin.$location
            : $origin.'/'.ltrim($location, '/');
    }

    /** @return list<string> */
    private static function systemResolve(string $host): array
    {
        $ips = [];
        $a = @gethostbynamel($host);
        if (is_array($a)) {
            $ips = $a;
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (isset($rec['ipv6'])) {
                    $ips[] = (string) $rec['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
