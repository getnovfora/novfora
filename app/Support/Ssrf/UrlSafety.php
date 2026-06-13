<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Ssrf;

/**
 * Shared, pure URL/redirect helpers used by every SSRF-guarded egress path (oEmbed + outbound webhooks), so
 * the redirect-following, host resolution, and connection-pinning logic is defined once. {@see IpClassifier}
 * is the companion address deny-list.
 */
final class UrlSafety
{
    /**
     * A redirect Location is unsafe to follow when it is empty/missing or carries a CR/LF — the latter is a
     * response-splitting / header- and log-injection vector, rejected before the value is absolutised or
     * logged. Belt-and-suspenders over the HTTP client (which also forbids control characters in header
     * values); a pure predicate so the CRLF branch is directly regression-tested.
     */
    public static function locationIsUnsafe(string $location): bool
    {
        return $location === '' || strpbrk($location, "\r\n") !== false;
    }

    /** Resolve a relative/absolute Location against the current URL. */
    public static function absolutize(string $location, string $base): string
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

    /**
     * Build a CURLOPT_RESOLVE pin list (host:port:ip) from validated IPs so the actual TCP connection cannot
     * be DNS-rebound to an internal address between validation and connect.
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    public static function resolvePins(string $host, array $ips, int $port): array
    {
        $pins = [];
        foreach ($ips as $ip) {
            $pins[] = "{$host}:{$port}:{$ip}";
        }

        return $pins;
    }

    /**
     * Resolve a hostname to every A + AAAA address. Used as the default resolver; guards accept an injected
     * resolver so DNS behaviour is deterministic under test.
     *
     * @return list<string>
     */
    public static function systemResolve(string $host): array
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
