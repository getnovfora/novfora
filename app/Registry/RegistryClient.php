<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Registry;

use App\Models\ModuleTrustKey;
use App\Models\RegistryInstall;
use App\Models\RegistryState;
use App\Modules\ModuleManager;
use App\Modules\ModuleTrustKeys;
use App\Modules\Packaging\ModuleInstaller;
use App\Modules\Packaging\PackageException;
use App\Support\Audit;
use App\Support\Ssrf\IpClassifier;
use App\Support\Ssrf\UrlSafety;
use App\Theme\Packaging\StylePackage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The NovFora Registry client (Phase 4C, NOV-125, ADR-0113, apex supply chain). Fetches the STATIC signed
 * feed, verifies it against the PINNED root key, and one-click-installs a listed package — riding the
 * UNTOUCHED ArchiveGuard + StylePackage (themes) / ModuleInstaller+PackageSignature (modules) paths. There is
 * no second install path. The threat model (docs/product/spike-registry-memo.md) is defended structurally:
 *
 *  - Feed tampering / mirror substitution → ed25519 detached signature over the EXACT feed bytes vs the
 *    pinned root key (a mirror serves bytes, never trust).
 *  - Feed rollback → a monotonic `sequence` refused below the last accepted one.
 *  - Zip mirror substitution → each version pins a sha256 INSIDE the signed feed; the downloaded bytes must
 *    match before any installer sees them.
 *  - Package downgrade → the one-click path never installs a version ≤ the installed one.
 *  - Revoked publisher → a revoked publisher's registry-managed trust key is disabled on every feed refresh,
 *    so no new install AND no upgrade signed by it can pass; installed packages are surfaced for review.
 *  - Unsigned / foreign package → never listed; the module path still verifies module.sig against the
 *    (registry-managed) trusted key, and a theme rides StylePackage's strict manifest validation.
 */
final class RegistryClient
{
    private const FEED_CACHE = 'novfora:registry:feed';

    public function __construct(
        private readonly StylePackage $styles,
        private readonly ModuleInstaller $modules,
        private readonly ModuleTrustKeys $trustKeys,
        private readonly ModuleManager $moduleManager,
    ) {}

    public function isConfigured(): bool
    {
        return (bool) config('novfora.registry.enabled')
            && (string) config('novfora.registry.feed_url') !== ''
            && (string) config('novfora.registry.root_public_key') !== ''
            && FeedSigner::available();
    }

    /**
     * The verified feed document (cached). Fetches feed.json + feed.json.sig, verifies the signature over the
     * exact bytes against the pinned root key, refuses a rolled-back sequence, then caches + returns it.
     *
     * @return array{sequence:int, generated_at:?string, publishers:array<string,array<string,mixed>>, packages:array<string,array<string,mixed>>, stale:bool}
     *
     * @throws RegistryException
     */
    public function feed(bool $force = false): array
    {
        if (! $this->isConfigured()) {
            throw new RegistryException('The registry is not configured (feed URL + pinned root key required).');
        }

        if (! $force) {
            $cached = Cache::get(self::FEED_CACHE);
            if (is_array($cached)) {
                // Enforce revocations even on the cached fast path (idempotent) so a disabled key/module can't
                // silently come back between fresh fetches; fresh revocations still arrive via the daily cron
                // refresh (routes/console.php) or a manual "check for updates".
                $this->processRevocations($cached);

                return $cached;
            }
        }

        $url = (string) config('novfora.registry.feed_url');
        try {
            $feedResp = $this->safeGet($url, 15, 4_194_304);   // 4 MiB feed cap (enforced mid-transfer)
            $sigResp = $this->safeGet($url.'.sig', 15, 8_192);  // a detached sig is ~88 bytes — cap tightly
        } catch (RegistryException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new RegistryException('Could not reach the registry.');
        }
        if (! $feedResp->ok() || ! $sigResp->ok()) {
            throw new RegistryException('The registry feed is unavailable.');
        }

        $bytes = (string) $feedResp->body();

        // (1) SIGNATURE FIRST — over the exact bytes, against the pinned root key. Nothing below runs on
        // untrusted content: a tampered or mirror-substituted feed dies here.
        if (! FeedSigner::verify($bytes, trim((string) $sigResp->body()), (string) config('novfora.registry.root_public_key'))) {
            throw new RegistryException('The registry feed signature is invalid — refusing it.');
        }

        $doc = json_decode($bytes, true);
        if (! is_array($doc) || (int) ($doc['schema_version'] ?? 0) !== 1) {
            throw new RegistryException('The registry feed format is unsupported.');
        }

        // (2) ROLLBACK — a verified feed whose sequence is below the last accepted one is a downgrade attack
        // (an attacker replaying an older, validly-signed feed to re-expose a since-revoked publisher). The
        // floor is stored DURABLY (registry_state row), not in the flushable cache — a `cache:clear` must
        // not reset it to 0 and re-open the replay window (apex finding).
        $sequence = (int) ($doc['sequence'] ?? 0);
        $lastSeq = $this->lastSequence();
        if ($sequence < $lastSeq) {
            throw new RegistryException('The registry feed is older than the last one seen — refusing a rollback.');
        }

        $verified = [
            'sequence' => $sequence,
            'generated_at' => is_string($doc['generated_at'] ?? null) ? $doc['generated_at'] : null,
            'publishers' => $this->indexPublishers($doc['publishers'] ?? []),
            'packages' => $this->indexPackages($doc['packages'] ?? []),
            'stale' => $this->isStale($doc['generated_at'] ?? null),
        ];

        $this->rememberSequence($sequence);
        Cache::put(self::FEED_CACHE, $verified, now()->addHours(6));

        // (3) Propagate revocations to the trusted-key registry on every refresh.
        $this->processRevocations($verified);

        return $verified;
    }

    /**
     * One-click install of a listed package (latest, or a specific version). Verifies the content hash the
     * signed feed pins, refuses a downgrade, checks the publisher is active, then hands the archive to the
     * EXISTING install path for its type.
     *
     * @throws RegistryException
     */
    public function install(string $slug, ?string $version = null): RegistryInstall
    {
        $feed = $this->feed();
        $package = $feed['packages'][$slug] ?? null;
        if ($package === null) {
            throw new RegistryException('That package is not in the registry.');
        }

        $type = (string) ($package['type'] ?? '');
        if (! in_array($type, ['module', 'theme'], true)) {
            throw new RegistryException('Unsupported package type.');
        }

        $target = $version ?? (string) ($package['latest'] ?? '');
        $ver = $package['versions'][$target] ?? null;
        if (! is_array($ver)) {
            throw new RegistryException('That version is not in the registry.');
        }

        // DOWNGRADE refusal — never install a version at or below what's already installed via the registry.
        $installed = RegistryInstall::query()->where('slug', $slug)->first();
        if ($installed !== null && version_compare($target, (string) $installed->version, '<=')) {
            throw new RegistryException('That version is not newer than what is installed.');
        }

        // PUBLISHER must be active (not revoked) in the signed feed.
        $fingerprint = (string) ($ver['publisher_fingerprint'] ?? '');
        $publisher = $feed['publishers'][$fingerprint] ?? null;
        if ($publisher === null || ($publisher['status'] ?? '') !== 'active') {
            throw new RegistryException('That package’s publisher is not active.');
        }

        // DOWNLOAD + CONTENT-ADDRESS: the bytes must match the sha256 the SIGNED feed pins (mirror
        // substitution refused) BEFORE any installer sees the archive.
        $zipUrl = (string) ($ver['zip_url'] ?? '');
        $expectSha = strtolower((string) ($ver['sha256'] ?? ''));
        if ($zipUrl === '' || strlen($expectSha) !== 64) {
            throw new RegistryException('The package entry is incomplete.');
        }

        try {
            $resp = $this->safeGet($zipUrl, 60, 67_108_864); // 64 MiB download cap (enforced mid-transfer)
        } catch (RegistryException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new RegistryException('Could not download the package.');
        }
        if (! $resp->ok()) {
            throw new RegistryException('The package download failed.');
        }
        $body = (string) $resp->body();
        if (! hash_equals($expectSha, hash('sha256', $body))) {
            throw new RegistryException('The package failed its integrity check — refusing it.');
        }

        $dir = (string) config('novfora.registry.staging_path');
        File::ensureDirectoryExists($dir);
        $zipPath = $dir.'/'.Str::uuid()->toString().'.zip';
        File::put($zipPath, $body);

        try {
            $target_ref = $this->dispatchInstall($type, $zipPath, $fingerprint, $publisher);
        } catch (PackageException $e) {
            throw new RegistryException('Install failed: '.$e->getMessage());
        } finally {
            @unlink($zipPath);
        }

        $record = RegistryInstall::query()->updateOrCreate(
            ['slug' => $slug],
            ['type' => $type, 'version' => $target, 'publisher_fingerprint' => $fingerprint, 'target' => $target_ref, 'installed_at' => now()],
        );

        Audit::log('registry.installed', null, ['slug' => $slug, 'version' => $target, 'type' => $type, 'publisher' => $fingerprint]);

        return $record;
    }

    /** Type-dispatched install through the EXISTING, untouched paths. Returns a reference to the installed thing. */
    private function dispatchInstall(string $type, string $zipPath, string $fingerprint, array $publisher): string
    {
        if ($type === 'theme') {
            $theme = $this->styles->import($zipPath);

            return (string) $theme->getKey();
        }

        // module — ensure the publisher's key is enrolled+enabled as a registry-managed trusted key, so
        // ModuleInstaller's mandatory ed25519 signature check can succeed for a legitimate publisher (and
        // FAIL once that key is revoked → disabled).
        $publicKey = (string) ($publisher['public_key_b64'] ?? '');
        if ($publicKey !== '') {
            $this->trustKeys->add('registry:'.substr($fingerprint, 0, 12), $publicKey);
        }
        $result = $this->modules->installFromZip($zipPath, allowUpgrade: true);

        return (string) $result['slug'];
    }

    /**
     * On a verified refresh, propagate REVOKED publishers: (1) disable their registry-managed trusted key so
     * no new install/upgrade signed by them can pass the module gate, and (2) when the policy is 'disable',
     * KILL-SWITCH their already-installed modules (an enabled module keeps executing until stopped — a stale
     * trust key doesn't unload it; apex finding). Operator-added trusted keys (not `registry:`-named) are
     * NEVER touched by the feed.
     *
     * @param  array{publishers:array<string,array<string,mixed>>}  $feed
     * @return list<string> fingerprints revoked this pass
     */
    public function processRevocations(array $feed): array
    {
        $revoked = [];
        $killSwitch = (string) config('novfora.registry.on_revoke', 'alert') === 'disable';

        foreach ($feed['publishers'] as $fingerprint => $publisher) {
            if (($publisher['status'] ?? '') !== 'revoked') {
                continue;
            }

            $key = ModuleTrustKey::query()
                ->where('fingerprint', $fingerprint)
                ->where('name', 'like', 'registry:%')
                ->where('is_enabled', true)
                ->first();
            if ($key instanceof ModuleTrustKey) {
                $this->trustKeys->setEnabled($key, false);
            }
            $revoked[] = $fingerprint;

            // Kill-switch: stop the installed code of a revoked publisher from executing (best-effort — a
            // disable failure must not break the feed refresh).
            if ($killSwitch) {
                foreach (RegistryInstall::query()->where('publisher_fingerprint', $fingerprint)->where('type', 'module')->get() as $install) {
                    try {
                        $this->moduleManager->disable((string) $install->target);
                    } catch (\Throwable) {
                        // module not present as an installed row / already disabled — skip
                    }
                }
            }
        }

        if ($revoked !== []) {
            Audit::log('registry.publisher_revoked', null, ['fingerprints' => $revoked, 'kill_switch' => $killSwitch]);
        }

        return $revoked;
    }

    /**
     * SSRF-safe, size-bounded GET: refuse an internal/blocked address and re-validate every redirect hop
     * against IpClassifier (a mirror is UNTRUSTED bytes and must not become an SSRF primitive), and ABORT the
     * transfer mid-stream once $maxBytes is exceeded so a hostile mirror can't OOM the worker with a giant
     * body (apex findings). The `allow_private` config is a loopback-dev / test escape hatch only. Mirrors
     * the WebhookUrlGuard / oEmbed SsrfGuard discipline, reusing the shared UrlSafety + IpClassifier.
     */
    private function safeGet(string $url, int $timeout, int $maxBytes): Response
    {
        // A curl xferinfo callback that aborts the transfer the moment the download exceeds the cap —
        // regardless of a lying/absent Content-Length (a chunked hostile body can't buffer past the cap).
        $abortOversize = static fn ($ch, int $dlTotal, int $dlNow): int => $dlNow > $maxBytes ? 1 : 0;

        if ((bool) config('novfora.registry.allow_private', false)) {
            $resp = Http::withOptions(['allow_redirects' => false, 'curl' => [
                CURLOPT_MAXFILESIZE => $maxBytes, CURLOPT_NOPROGRESS => false, CURLOPT_XFERINFOFUNCTION => $abortOversize,
            ]])->timeout($timeout)->get($url);

            return $this->enforceSize($resp, $maxBytes);
        }

        $current = $url;
        for ($hop = 0; $hop <= 4; $hop++) {
            [$host, $ips, $port] = $this->validateHost($current);
            $resp = Http::withOptions([
                'allow_redirects' => false, // follow manually so every hop is re-validated
                'connect_timeout' => min(5, $timeout),
                // Pin host → a validated IP so the TCP connection can't be DNS-rebound to an internal address
                // between validation and connect; cap the transfer size (all ignored by Http::fake in tests).
                'curl' => [
                    CURLOPT_RESOLVE => UrlSafety::resolvePins($host, $ips, $port),
                    CURLOPT_MAXFILESIZE => $maxBytes,
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_XFERINFOFUNCTION => $abortOversize,
                ],
            ])->timeout($timeout)->get($current);

            $status = $resp->status();
            if ($status < 300 || $status >= 400) {
                return $this->enforceSize($resp, $maxBytes);
            }
            $location = (string) $resp->header('Location');
            if (UrlSafety::locationIsUnsafe($location)) {
                throw new RegistryException('The registry redirected to an unsafe location.');
            }
            $current = UrlSafety::absolutize($location, $current);
        }

        throw new RegistryException('The registry exceeded the redirect limit.');
    }

    /** Backstop the byte cap after the fetch (the curl abort is the primary fence; this catches the fake path). */
    private function enforceSize(Response $resp, int $maxBytes): Response
    {
        if (strlen((string) $resp->body()) > $maxBytes) {
            throw new RegistryException('The registry response exceeded its size limit.');
        }

        return $resp;
    }

    /**
     * Validate a URL's host resolves only to non-blocked (public) addresses.
     *
     * @return array{0:string,1:list<string>,2:int} [host, ips, port]
     *
     * @throws RegistryException
     */
    private function validateHost(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($parts === false || ! isset($parts['host']) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new RegistryException('The registry URL must be http(s).');
        }
        $host = (string) $parts['host'];
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : UrlSafety::systemResolve($host);
        if ($ips === []) {
            throw new RegistryException('The registry host does not resolve.');
        }
        foreach ($ips as $ip) {
            if (IpClassifier::isBlocked($ip)) {
                throw new RegistryException('The registry host resolves to a blocked address — refusing it.');
            }
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return [$host, $ips, $port];
    }

    /**
     * Installed packages whose publisher is REVOKED in the current verified feed — the ACP security alert.
     *
     * @return list<array{slug:string,type:string,version:string,reason:string}>
     */
    public function revocationAlerts(): array
    {
        try {
            $feed = $this->feed();
        } catch (RegistryException) {
            return [];
        }

        $alerts = [];
        foreach (RegistryInstall::query()->get() as $install) {
            $publisher = $feed['publishers'][$install->publisher_fingerprint] ?? null;
            if ($publisher !== null && ($publisher['status'] ?? '') === 'revoked') {
                $alerts[] = [
                    'slug' => (string) $install->slug,
                    'type' => (string) $install->type,
                    'version' => (string) $install->version,
                    'reason' => (string) ($publisher['revocation_reason'] ?? 'The publisher key was revoked.'),
                ];
            }
        }

        return $alerts;
    }

    /** Drop the cached feed (used after a manual "check for updates"). */
    public function refresh(): array
    {
        Cache::forget(self::FEED_CACHE);

        return $this->feed(force: true);
    }

    /**
     * Index publishers by a fingerprint DERIVED from their ed25519 public key — sha256(raw key), the exact
     * value ModuleTrustKeys stores — NOT the feed's opaque `fingerprint` field (apex finding: trusting the
     * feed's string decoupled the revocation lookup from the enrolled key, silently no-op-ing revocation).
     * A publisher without a valid 32-byte key is dropped (it can never be trusted or matched anyway); a
     * version's `publisher_fingerprint` therefore only resolves when it equals sha256(the key), which is what
     * makes revocation reach the enrolled trust-key row.
     *
     * @return array<string,array<string,mixed>> keyed by the derived fingerprint
     */
    private function indexPublishers(mixed $publishers): array
    {
        $out = [];
        if (is_array($publishers)) {
            foreach ($publishers as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $raw = base64_decode((string) ($p['public_key_b64'] ?? ''), true);
                if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    continue;
                }
                $fingerprint = hash('sha256', $raw);
                $p['fingerprint'] = $fingerprint; // authoritative — overrides whatever the feed claimed
                $out[$fingerprint] = $p;
            }
        }

        return $out;
    }

    /** The durable monotonic sequence floor (survives a cache clear). */
    private function lastSequence(): int
    {
        try {
            $row = RegistryState::query()->find('last_sequence');

            return $row !== null ? (int) $row->value : 0;
        } catch (\Throwable) {
            return 0; // table not ready (pre-install) — nothing accepted yet
        }
    }

    /** Advance the durable sequence floor (never lowers it). */
    private function rememberSequence(int $sequence): void
    {
        try {
            RegistryState::query()->updateOrCreate(['key' => 'last_sequence'], ['value' => (string) $sequence]);
        } catch (\Throwable) {
            // pre-install; the next verified feed re-attempts
        }
    }

    /** @param mixed $packages @return array<string,array<string,mixed>> keyed by slug, versions keyed by version */
    private function indexPackages(mixed $packages): array
    {
        $out = [];
        if (is_array($packages)) {
            foreach ($packages as $pkg) {
                if (! is_array($pkg) || ! is_string($pkg['slug'] ?? null)) {
                    continue;
                }
                $versions = [];
                foreach ($pkg['versions'] ?? [] as $v) {
                    if (is_array($v) && is_string($v['version'] ?? null)) {
                        $versions[$v['version']] = $v;
                    }
                }
                $pkg['versions'] = $versions;
                $out[$pkg['slug']] = $pkg;
            }
        }

        return $out;
    }

    private function isStale(mixed $generatedAt): bool
    {
        if (! is_string($generatedAt)) {
            return false;
        }
        try {
            $days = (int) config('novfora.registry.stale_after_days', 30);

            return Carbon::parse($generatedAt)->lt(now()->subDays($days));
        } catch (\Throwable) {
            return false;
        }
    }
}
