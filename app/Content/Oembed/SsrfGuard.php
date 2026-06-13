<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Content\Oembed;

use App\Support\Ssrf\IpClassifier;
use App\Support\Ssrf\UrlSafety;
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
        $this->resolver = $resolver ?? fn (string $host): array => UrlSafety::systemResolve($host);
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
                    if (self::locationIsUnsafe($location)) {
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
     * Classify an IP as unsafe to connect to — delegates to the shared {@see IpClassifier}, the single source
     * of truth for the SSRF deny-list across the oEmbed and webhook egress guards. Kept as a method here so
     * the existing oEmbed SSRF battery exercises the same surface.
     */
    public function isBlockedIp(string $ip): bool
    {
        return IpClassifier::isBlocked($ip);
    }

    /**
     * Build the CURLOPT_RESOLVE pin list (host:port:ip) from the validated IPs, for both ports.
     *
     * @param  array{host:string, ips:list<string>}  $validated
     * @return list<string>
     */
    private function resolvePins(array $validated, string $url): array
    {
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);

        return UrlSafety::resolvePins($validated['host'], $validated['ips'], $port);
    }

    /**
     * A redirect Location is unsafe to follow when it is empty/missing or carries a CR/LF — the latter is a
     * response-splitting / header- and log-injection vector, rejected before the value is absolutised or
     * logged. Belt-and-suspenders over the HTTP client, which also forbids control characters in header
     * values; extracted as a pure predicate so the CRLF branch is directly regression-tested.
     */
    private static function locationIsUnsafe(string $location): bool
    {
        return UrlSafety::locationIsUnsafe($location);
    }

    /** Resolve a relative/absolute Location against the current URL. */
    private function absolutize(string $location, string $base): string
    {
        return UrlSafety::absolutize($location, $base);
    }
}
