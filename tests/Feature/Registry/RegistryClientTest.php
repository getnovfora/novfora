<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Module;
use App\Models\ModuleTrustKey;
use App\Models\RegistryInstall;
use App\Models\RegistryState;
use App\Models\SiteTheme;
use App\Modules\ModuleTrustKeys;
use App\Registry\FeedSigner;
use App\Registry\RegistryClient;
use App\Registry\RegistryException;
use App\Theme\Packaging\StylePackage;
use App\Theme\StyleThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| Registry v1 (NOV-125 / ADR-0113) — the apex supply-chain surface. The threat model is defended
| STRUCTURALLY: a tampered/mirror-substituted feed, a rollback, a substituted package, a downgrade, and a
| revoked publisher are each refused by construction, and one-click install rides the untouched
| ArchiveGuard/StylePackage path. These tests are the adversarial battery.
*/

uses(RefreshDatabase::class);

const FEED_URL = 'https://registry.test/registry.json';

/** Fresh ed25519 root keypair (base64). */
function rootKeys(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [base64_encode(sodium_crypto_sign_publickey($pair)), base64_encode(sodium_crypto_sign_secretkey($pair))];
}

/** Build a real style-package zip, returning [bytes, sha256]. */
function themeZipBytes(): array
{
    Storage::fake('public');
    $theme = app(StyleThemeManager::class)->create(['name' => 'Registry Theme', 'tokens' => ['surface' => '#f0f0ff']]);
    $path = app(StylePackage::class)->export($theme);
    $bytes = (string) file_get_contents($path);
    @unlink($path);
    $theme->delete(); // leave a clean slate — the registry install re-creates it
    Cache::flush();

    return [$bytes, hash('sha256', $bytes)];
}

/**
 * Configure the registry, build + sign a feed, and Http::fake the feed, its .sig, and every zip_url.
 * $mutate can alter the feed doc (for adversarial cases) BEFORE signing; $badSig signs different bytes.
 */
function fakeRegistry(array $feedDoc, string $secret, string $public, array $zipMap = [], bool $tamperAfterSign = false): void
{
    config()->set('novfora.registry.enabled', true);
    config()->set('novfora.registry.feed_url', FEED_URL);
    config()->set('novfora.registry.root_public_key', $public);
    // Happy-path fakes use a .test host that doesn't resolve; the SSRF guard's real behaviour is exercised
    // by the dedicated SSRF tests (allow_private = false there).
    config()->set('novfora.registry.allow_private', true);

    $bytes = json_encode($feedDoc);
    $sig = FeedSigner::sign($bytes, $secret);
    if ($tamperAfterSign) {
        // A mirror alters a byte after the (valid) signature was produced.
        $feedDoc['packages'][0]['title'] = 'TAMPERED';
        $bytes = json_encode($feedDoc);
    }

    $fakes = [
        FEED_URL => Http::response($bytes, 200),
        FEED_URL.'.sig' => Http::response($sig, 200),
    ];
    foreach ($zipMap as $url => $zbytes) {
        $fakes[$url] = Http::response($zbytes, 200);
    }
    Http::fake($fakes);
}

/** A publisher's canonical fingerprint = sha256(raw ed25519 public key) — the value ModuleTrustKeys stores. */
function pubFingerprint(string $publicKeyB64): string
{
    return hash('sha256', (string) base64_decode($publicKeyB64, true));
}

function baseFeed(string $themeZipSha): array
{
    // A real first-party publisher key; every version references its DERIVED fingerprint (sha256 of the key),
    // which is what the client keys publishers by and what revocation matches against.
    $pubKey = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
    $fp = pubFingerprint($pubKey);

    return [
        'schema_version' => 1,
        'sequence' => 10,
        'generated_at' => now()->toIso8601String(),
        'publishers' => [
            ['fingerprint' => $fp, 'public_key_b64' => $pubKey, 'name' => 'NovFora', 'status' => 'active'],
        ],
        'packages' => [
            ['slug' => 'novfora/qa', 'type' => 'module', 'title' => 'Q&A', 'description' => 'Questions and answers', 'latest' => '1.0.0',
                'versions' => [['version' => '1.0.0', 'api_version' => '1.2', 'zip_url' => 'https://registry.test/pkgs/qa-1.0.0.zip', 'sha256' => str_repeat('a', 64), 'publisher_fingerprint' => $fp]]],
            ['slug' => 'novfora/ocean-theme', 'type' => 'theme', 'title' => 'Ocean', 'description' => 'A calm blue theme', 'latest' => '1.0.0',
                'versions' => [['version' => '1.0.0', 'zip_url' => 'https://registry.test/pkgs/ocean-1.0.0.zip', 'sha256' => $themeZipSha, 'publisher_fingerprint' => $fp]]],
            ['slug' => 'novfora/hello', 'type' => 'module', 'title' => 'Hello', 'description' => 'An example plugin', 'latest' => '1.0.0',
                'versions' => [['version' => '1.0.0', 'api_version' => '1.2', 'zip_url' => 'https://registry.test/pkgs/hello-1.0.0.zip', 'sha256' => str_repeat('b', 64), 'publisher_fingerprint' => $fp]]],
        ],
    ];
}

it('verifies a signed feed and lists at least three first-party packages', function () {
    [$pub, $sec] = rootKeys();
    [, $sha] = themeZipBytes();
    fakeRegistry(baseFeed($sha), $sec, $pub);

    $feed = app(RegistryClient::class)->feed();
    expect($feed['sequence'])->toBe(10)
        ->and(count($feed['packages']))->toBeGreaterThanOrEqual(3)
        ->and($feed['packages'])->toHaveKey('novfora/ocean-theme');
});

it('refuses a feed whose bytes were altered after signing (tamper / mirror substitution)', function () {
    [$pub, $sec] = rootKeys();
    [, $sha] = themeZipBytes();
    fakeRegistry(baseFeed($sha), $sec, $pub, [], tamperAfterSign: true);

    expect(fn () => app(RegistryClient::class)->feed())
        ->toThrow(RegistryException::class, 'signature is invalid');
});

it('refuses a feed signed by the WRONG key (pinned root key is authoritative)', function () {
    [$pub] = rootKeys();
    [, $badSec] = rootKeys(); // a different key signs the feed
    [, $sha] = themeZipBytes();
    fakeRegistry(baseFeed($sha), $badSec, $pub);

    expect(fn () => app(RegistryClient::class)->feed())
        ->toThrow(RegistryException::class, 'signature is invalid');
});

it('refuses a rolled-back feed sequence, and the floor survives a cache clear (durable — apex M1)', function () {
    [$pub, $sec] = rootKeys();
    [, $sha] = themeZipBytes();

    // We have previously accepted sequence 10 (persisted durably). An attacker replays an OLDER, still
    // validly-signed feed (sequence 9) to re-expose a since-revoked publisher — refused.
    RegistryState::create(['key' => 'last_sequence', 'value' => '10']);
    $old = baseFeed($sha);
    $old['sequence'] = 9;
    fakeRegistry($old, $sec, $pub);

    expect(fn () => app(RegistryClient::class)->feed())
        ->toThrow(RegistryException::class, 'rollback');

    // A routine cache clear must NOT reset the floor (the whole point of the durable store).
    Cache::flush();
    expect(fn () => app(RegistryClient::class)->feed())
        ->toThrow(RegistryException::class, 'rollback');
});

it('installs a theme end-to-end: verified feed → sha256-checked zip → StylePackage import', function () {
    [$pub, $sec] = rootKeys();
    [$zip, $sha] = themeZipBytes();
    Storage::fake('public');
    fakeRegistry(baseFeed($sha), $sec, $pub, ['https://registry.test/pkgs/ocean-1.0.0.zip' => $zip]);

    $record = app(RegistryClient::class)->install('novfora/ocean-theme');

    expect($record->type)->toBe('theme')
        ->and($record->version)->toBe('1.0.0')
        ->and(SiteTheme::where('name', 'Registry Theme')->exists())->toBeTrue()
        ->and(RegistryInstall::where('slug', 'novfora/ocean-theme')->exists())->toBeTrue();
});

it('refuses a package whose downloaded bytes do not match the feed sha256 (mirror substitution)', function () {
    [$pub, $sec] = rootKeys();
    [$zip, $sha] = themeZipBytes();
    Storage::fake('public');
    // The mirror serves DIFFERENT bytes than the feed pins.
    fakeRegistry(baseFeed($sha), $sec, $pub, ['https://registry.test/pkgs/ocean-1.0.0.zip' => $zip.'evil-extra-bytes']);

    expect(fn () => app(RegistryClient::class)->install('novfora/ocean-theme'))
        ->toThrow(RegistryException::class, 'integrity check');
    expect(SiteTheme::where('name', 'Registry Theme')->exists())->toBeFalse();
});

it('refuses to install a package from a revoked publisher', function () {
    [$pub, $sec] = rootKeys();
    [$zip, $sha] = themeZipBytes();
    Storage::fake('public');
    $feed = baseFeed($sha);
    $feed['publishers'][0]['status'] = 'revoked';
    $feed['publishers'][0]['revocation_reason'] = 'Key compromised.';
    fakeRegistry($feed, $sec, $pub, ['https://registry.test/pkgs/ocean-1.0.0.zip' => $zip]);

    expect(fn () => app(RegistryClient::class)->install('novfora/ocean-theme'))
        ->toThrow(RegistryException::class, 'not active');
});

it('refuses a downgrade to a version at or below the installed one', function () {
    [$pub, $sec] = rootKeys();
    [$zip, $sha] = themeZipBytes();
    Storage::fake('public');
    fakeRegistry(baseFeed($sha), $sec, $pub, ['https://registry.test/pkgs/ocean-1.0.0.zip' => $zip]);
    app(RegistryClient::class)->install('novfora/ocean-theme'); // installs 1.0.0

    expect(fn () => app(RegistryClient::class)->install('novfora/ocean-theme', '1.0.0'))
        ->toThrow(RegistryException::class, 'not newer');
});

it('disables a revoked publisher\'s registry-managed trust key by the DERIVED fingerprint (apex H1)', function () {
    [$pub, $sec] = rootKeys();
    [, $sha] = themeZipBytes();

    // A registry-managed trust key for the publisher exists (as if a module was installed). Its stored
    // fingerprint is sha256(raw key) — and the feed publisher carries that SAME key, so its DERIVED
    // fingerprint matches. (The pre-fix bug: the feed's opaque fingerprint decoupled the two, so revocation
    // silently found no row and left the compromised key enabled.)
    $pair = sodium_crypto_sign_keypair();
    $keyB64 = base64_encode(sodium_crypto_sign_publickey($pair));
    $key = app(ModuleTrustKeys::class)->add('registry:pub', $keyB64);
    $derivedFp = $key->fingerprint; // == sha256(raw key)
    expect($derivedFp)->toBe(pubFingerprint($keyB64)); // the coupling the fix enforces

    RegistryInstall::create(['slug' => 'novfora/qa', 'type' => 'module', 'version' => '1.0.0', 'publisher_fingerprint' => $derivedFp, 'target' => 'qa', 'installed_at' => now()]);

    // A distinct OPERATOR key (not registry-managed) that must NOT be touched by the feed.
    $opKey = app(ModuleTrustKeys::class)->add('my-own-key', base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())));

    // The feed publisher uses the SAME key (so its derived fingerprint == the enrolled row's), status revoked.
    $feed = baseFeed($sha);
    $feed['publishers'] = [
        ['fingerprint' => 'whatever-opaque-string-ignored', 'public_key_b64' => $keyB64, 'name' => 'NovFora', 'status' => 'revoked', 'revocation_reason' => 'Compromised.'],
    ];
    $feed['packages'][0]['versions'][0]['publisher_fingerprint'] = $derivedFp;
    fakeRegistry($feed, $sec, $pub);

    $client = app(RegistryClient::class);
    $client->feed(); // triggers processRevocations()

    expect(ModuleTrustKey::find($key->id)->is_enabled)->toBeFalse()   // registry key DISABLED (revocation reached it)
        ->and(ModuleTrustKey::find($opKey->id)->is_enabled)->toBeTrue(); // operator key untouched

    $alerts = $client->revocationAlerts();
    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['slug'])->toBe('novfora/qa');
});

it('drops a publisher with an invalid/absent key so a version referencing it cannot install (apex H1)', function () {
    [$pub, $sec] = rootKeys();
    [, $sha] = themeZipBytes();
    Storage::fake('public');

    // A hostile feed lists an opaque fingerprint with NO valid key, then points the theme at it — the
    // publisher is dropped (no valid key → no derived fingerprint), so the version's publisher can't resolve.
    $feed = baseFeed($sha);
    $feed['publishers'] = [['fingerprint' => 'opaque', 'public_key_b64' => 'not-a-key', 'name' => 'X', 'status' => 'active']];
    $feed['packages'][1]['versions'][0]['publisher_fingerprint'] = 'opaque';
    fakeRegistry($feed, $sec, $pub, ['https://registry.test/pkgs/ocean-1.0.0.zip' => 'x']);

    expect(fn () => app(RegistryClient::class)->install('novfora/ocean-theme'))
        ->toThrow(RegistryException::class, 'not active');
});

it('is inert when unconfigured (no feed URL / root key)', function () {
    config()->set('novfora.registry.root_public_key', '');
    expect(app(RegistryClient::class)->isConfigured())->toBeFalse();
    expect(fn () => app(RegistryClient::class)->feed())->toThrow(RegistryException::class, 'not configured');
});

it('refuses a feed URL that resolves to a blocked internal address — SSRF (apex M2)', function () {
    [$pub] = rootKeys();
    config()->set('novfora.registry.enabled', true);
    config()->set('novfora.registry.root_public_key', $pub);
    config()->set('novfora.registry.allow_private', false);         // the production posture
    config()->set('novfora.registry.feed_url', 'http://127.0.0.1/registry.json'); // internal IP literal

    expect(fn () => app(RegistryClient::class)->feed())
        ->toThrow(RegistryException::class, 'blocked address');
});

it('refuses a mirror redirect to an internal address (cloud-metadata SSRF) — apex M2', function () {
    [$pub, $sec] = rootKeys();
    [, $sha] = themeZipBytes();
    config()->set('novfora.registry.enabled', true);
    config()->set('novfora.registry.root_public_key', $pub);
    config()->set('novfora.registry.allow_private', false);
    config()->set('novfora.registry.feed_url', 'http://8.8.8.8/registry.json'); // public host, resolves fine

    // The (untrusted) mirror answers with a redirect toward the cloud-metadata endpoint.
    Http::fake([
        'http://8.8.8.8/registry.json' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    expect(fn () => app(RegistryClient::class)->feed())
        ->toThrow(RegistryException::class, 'blocked address');
});

it('enforces revocation on the cached fast path so a disabled key cannot silently come back (apex M3)', function () {
    [$pub, $sec] = rootKeys();
    [, $sha] = themeZipBytes();

    $pair = sodium_crypto_sign_keypair();
    $keyB64 = base64_encode(sodium_crypto_sign_publickey($pair));
    $key = app(ModuleTrustKeys::class)->add('registry:pub', $keyB64);
    $feed = baseFeed($sha);
    $feed['publishers'] = [['fingerprint' => 'x', 'public_key_b64' => $keyB64, 'name' => 'Acme', 'status' => 'revoked', 'revocation_reason' => 'r']];
    fakeRegistry($feed, $sec, $pub);

    app(RegistryClient::class)->feed(); // fresh: disables the key + caches the feed
    expect(ModuleTrustKey::find($key->id)->is_enabled)->toBeFalse();

    // Something re-enables the key; a CACHED read must re-disable it (processRevocations on the fast path).
    ModuleTrustKey::where('id', $key->id)->update(['is_enabled' => true]);
    app(RegistryClient::class)->feed(); // cached fast path
    expect(ModuleTrustKey::find($key->id)->is_enabled)->toBeFalse();
});

it('refuses an over-cap response body (hostile-mirror memory DoS — apex M4)', function () {
    [$pub] = rootKeys();
    config()->set('novfora.registry.enabled', true);
    config()->set('novfora.registry.root_public_key', $pub);
    config()->set('novfora.registry.allow_private', false);
    config()->set('novfora.registry.feed_url', 'http://8.8.8.8/registry.json'); // public host, passes validateHost

    // The untrusted mirror returns a body larger than the 4 MiB feed cap.
    Http::fake(['http://8.8.8.8/registry.json' => Http::response(str_repeat('x', 4_194_305), 200)]);

    expect(fn () => app(RegistryClient::class)->feed())
        ->toThrow(RegistryException::class, 'size limit');
});

it('kill-switches an installed module from a revoked publisher when on_revoke=disable (apex H2)', function () {
    [$pub, $sec] = rootKeys();
    [, $sha] = themeZipBytes();
    config()->set('novfora.registry.on_revoke', 'disable');

    // An installed module from the publisher, enabled and loaded.
    $pair = sodium_crypto_sign_keypair();
    $keyB64 = base64_encode(sodium_crypto_sign_publickey($pair));
    $fp = pubFingerprint($keyB64);
    Module::create(['slug' => 'acme/widget', 'name' => 'Widget', 'version' => '1.0.0', 'api_version' => '^1.2', 'enabled' => true, 'installed_at' => now()]);
    RegistryInstall::create(['slug' => 'acme/widget', 'type' => 'module', 'version' => '1.0.0', 'publisher_fingerprint' => $fp, 'target' => 'acme/widget', 'installed_at' => now()]);

    $feed = baseFeed($sha);
    $feed['publishers'] = [['fingerprint' => 'x', 'public_key_b64' => $keyB64, 'name' => 'Acme', 'status' => 'revoked', 'revocation_reason' => 'Compromised.']];
    fakeRegistry($feed, $sec, $pub);

    app(RegistryClient::class)->feed(); // triggers processRevocations() with kill-switch

    expect(Module::where('slug', 'acme/widget')->value('enabled'))->toBe(false);
});
