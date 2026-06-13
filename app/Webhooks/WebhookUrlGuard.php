<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Webhooks;

use App\Support\Ssrf\IpClassifier;
use App\Support\Ssrf\SsrfException;
use App\Support\Ssrf\UrlSafety;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * SSRF guard for outbound webhook delivery (ADR-0033, hardened in the Phase-3 hardening pass). An admin
 * supplies the endpoint URL, so every delivery is an SSRF surface — and because delivery happens LATER (on a
 * cron tick) than the create/update-time check, a hostname that looked public when saved can be re-pointed at
 * an internal address before it is hit (DNS rebinding). This guard closes that gap:
 *
 *   • {@see assertConfigUrl()} — the cheap create/update-time check (scheme + literal-IP + obvious internal
 *     hostnames). It does NOT resolve DNS, because the authoritative defence is delivery-time and a public
 *     hostname's A records are not knowable (or stable) when the endpoint is saved.
 *   • {@see deliver()} — the authoritative boundary, run AT DELIVERY: resolve every A/AAAA record, block the
 *     send if ANY resolved address is private/loopback/link-local/reserved/CGNAT/metadata/IPv6-ULA/mapped,
 *     pin host → a validated IP for the actual connection (CURLOPT_RESOLVE) so it can't be rebound between
 *     resolve and connect, and RE-VALIDATE after every redirect hop (a 30x to an internal URL is the classic
 *     bypass).
 *
 * Address classification is delegated to {@see IpClassifier} — the same deny-list the oEmbed fetcher uses.
 * The resolver is injectable so rebinding/metadata attempts are deterministically testable without real DNS.
 */
final class WebhookUrlGuard
{
    /** @var callable(string): list<string> resolves a hostname to a list of IP strings (A + AAAA) */
    private $resolver;

    /** @param (callable(string): list<string>)|null $resolver */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? fn (string $host): array => UrlSafety::systemResolve($host);
    }

    /**
     * The create/update-time check (no DNS). Rejects a non-http(s) scheme, a private/reserved IP literal, and
     * the obvious internal hostnames. `novfora.webhooks.allow_private` opens it for local dev only.
     *
     * @throws InvalidArgumentException with an operator-facing message (surfaced inline in the ACP)
     */
    public function assertConfigUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('Webhook URL must be a valid http(s) URL.');
        }
        if ($this->allowPrivate()) {
            return;
        }
        if ($this->isObviouslyInternal($host)) {
            throw new InvalidArgumentException('Webhook URL may not target a loopback, private, or reserved address.');
        }
    }

    /**
     * Deliver a signed POST to an admin-configured URL, SSRF-safe. Validates + pins + follows up to
     * $maxRedirects redirects (re-validating each hop). Returns the final non-redirect {@see Response}.
     *
     * @param  array<string,string>  $headers  signature + timestamp + content headers
     *
     * @throws SsrfException when the target (or any redirect hop) resolves to a blocked address, does not
     *                       resolve, has an unsafe redirect Location, or exceeds the redirect cap
     */
    public function deliver(string $url, string $body, array $headers, int $timeout = 10, int $maxRedirects = 3): Response
    {
        // Local-dev escape hatch: skip resolution/pinning entirely (an operator has explicitly opted into
        // hitting private hosts). NEVER true in production.
        if ($this->allowPrivate()) {
            return Http::timeout($timeout)->withHeaders($headers)->withBody($body, 'application/json')->post($url);
        }

        $current = $url;
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            ['host' => $host, 'ips' => $ips, 'port' => $port] = $this->validate($current);

            $response = Http::withHeaders($headers)
                ->withBody($body, 'application/json')
                ->withOptions([
                    'allow_redirects' => false, // we follow manually so we can re-validate each hop
                    'connect_timeout' => min(5, $timeout),
                    // Pin host → a validated IP so the TCP connection cannot be DNS-rebound to an internal
                    // address between validation and connect. (Ignored by Http::fake in tests.)
                    'curl' => [CURLOPT_RESOLVE => UrlSafety::resolvePins($host, $ips, $port)],
                ])
                ->timeout($timeout)
                ->post($current);

            $status = $response->status();
            if ($status < 300 || $status >= 400) {
                return $response;
            }

            // A redirect: re-validate the Location host on the next loop iteration.
            $location = (string) $response->header('Location');
            if (UrlSafety::locationIsUnsafe($location)) {
                throw new SsrfException('Webhook redirect carried an unsafe or missing Location.');
            }
            $current = UrlSafety::absolutize($location, $current);
        }

        throw new SsrfException('Webhook delivery exceeded the redirect limit.');
    }

    /**
     * Resolve + validate one URL: http(s) scheme, host present, and every resolved (or literal) IP public.
     *
     * @return array{host:string, ips:list<string>, port:int}
     *
     * @throws SsrfException
     */
    private function validate(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new SsrfException('Unparseable webhook URL.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new SsrfException('Webhook URL must be http(s).');
        }

        $host = (string) $parts['host'];
        // Strip the brackets of an IPv6 literal host ONLY when properly paired (a malformed "[::1" stays as-is
        // and fails IP/resolve validation rather than being silently rewritten).
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '') {
            throw new SsrfException('Webhook URL is missing a host.');
        }

        // If the host IS an IP literal, validate it directly (no DNS). Otherwise resolve every record.
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            throw new SsrfException("Webhook host {$host} does not resolve.");
        }
        foreach ($ips as $ip) {
            if (IpClassifier::isBlocked($ip)) {
                throw new SsrfException("Webhook host {$host} resolves to a blocked address ({$ip}).");
            }
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return ['host' => $host, 'ips' => $ips, 'port' => $port];
    }

    private function isObviouslyInternal(string $host): bool
    {
        $host = strtolower(trim($host, '[]')); // strip IPv6 brackets
        if (in_array($host, ['localhost', '0.0.0.0'], true)) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return IpClassifier::isBlocked($host);
        }

        // Hostnames: refuse the obvious internal suffixes (DNS is resolved + re-checked at delivery time).
        return str_ends_with($host, '.local') || str_ends_with($host, '.internal');
    }

    private function allowPrivate(): bool
    {
        return (bool) config('novfora.webhooks.allow_private', false);
    }
}
