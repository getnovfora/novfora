<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Upgrade\Releases\GitHubReleaseClient;
use App\Upgrade\Releases\ReleaseException;
use App\Upgrade\Releases\ReleaseVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function releaseFixture(array $entries = ['artisan' => 'hello'], string $version = '1.4.0', ?array $manifestFiles = null): array
{
    $dir = sys_get_temp_dir().'/novfora-release-test-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $zipPath = $dir.'/novfora-release.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $hashes = [];
    foreach ($entries as $name => $body) {
        $zip->addFromString($name, $body);
        if (! str_ends_with($name, '/')) {
            $hashes[$name] = hash('sha256', $body);
        }
    }
    ksort($hashes);
    $zip->addFromString('release-manifest.json', json_encode([
        'format' => 1, 'version' => $version, 'php' => '>=8.3', 'files' => $manifestFiles ?? $hashes,
    ], JSON_THROW_ON_ERROR));
    $zip->close();
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);
    $secret = sodium_crypto_sign_secretkey($pair);
    file_put_contents($zipPath.'.sig', json_encode([
        'key_id' => 'test',
        'signature' => base64_encode(sodium_crypto_sign_detached(file_get_contents($zipPath), $secret)),
    ], JSON_THROW_ON_ERROR));
    config(['releases.trusted_keys' => ['test' => base64_encode($public)]]);

    return [$zipPath, $dir];
}

afterEach(function (): void {
    Cache::forget('novfora:release:latest');
});

it('discovers only a stable signed release from the fixed repository and caches it', function (): void {
    $assets = [];
    foreach (['novfora-release.zip', 'novfora-release.zip.sig'] as $name) {
        $assets[] = ['name' => $name, 'browser_download_url' => "https://github.com/getnovfora/novfora/releases/download/v1.4.0/{$name}"];
    }
    Http::fake(['api.github.com/*' => Http::response([
        'draft' => false, 'prerelease' => false, 'tag_name' => 'v1.4.0',
        'published_at' => '2026-11-06T00:00:00Z', 'assets' => $assets,
    ])]);
    $client = new GitHubReleaseClient;
    expect($client->latest()['version'])->toBe('1.4.0');
    expect($client->latest()['tag'])->toBe('v1.4.0');
    Http::assertSentCount(1);
    $client->latest(true);
    Http::assertSentCount(2);
});

it('refuses prereleases, unsigned releases and asset host substitutions', function (): void {
    $base = [
        'draft' => false, 'prerelease' => false, 'tag_name' => 'v1.4.0',
        'published_at' => '2026-11-06T00:00:00Z',
        'assets' => [['name' => 'novfora-release.zip', 'browser_download_url' => 'https://evil.example/novfora-release.zip']],
    ];
    Http::fake(['api.github.com/*' => Http::sequence()
        ->push($base)->push([...$base, 'prerelease' => true])]);
    expect((new GitHubReleaseClient)->latest())->toBeNull();
    expect((new GitHubReleaseClient)->latest(true))->toBeNull();
});

it('verifies exact archive bytes, the manifest and safe staged paths', function (): void {
    [$zip, $dir] = releaseFixture();
    $verifier = new ReleaseVerifier;
    expect($verifier->verify($zip, $zip.'.sig', '1.4.0')['version'])->toBe('1.4.0');
    expect($verifier->stage($zip, $dir.'/staged', '1.4.0')['files'])->toHaveKey('artisan');
    expect(file_get_contents($dir.'/staged/artisan'))->toBe('hello');
    expect(fn () => $verifier->verify($zip, $zip.'.sig', '1.5.0'))->toThrow(ReleaseException::class);
});

it('rejects stripped and forged signatures even with a signed manifest', function (): void {
    [$zip] = releaseFixture();
    $sig = $zip.'.sig';
    $verifier = new ReleaseVerifier;
    unlink($sig);
    expect(fn () => $verifier->verify($zip, $sig, '1.4.0'))->toThrow(ReleaseException::class);
    file_put_contents($sig, json_encode(['key_id' => 'test', 'signature' => base64_encode(random_bytes(64))]));
    expect(fn () => $verifier->verify($zip, $sig, '1.4.0'))->toThrow(ReleaseException::class);
});

it('rejects archive edits after signing and a manifest that omits a file', function (): void {
    [$zip] = releaseFixture();
    $archive = new ZipArchive;
    $archive->open($zip);
    $archive->addFromString('extra.txt', 'not in manifest');
    $archive->close();
    expect(fn () => (new ReleaseVerifier)->verify($zip, $zip.'.sig', '1.4.0'))
        ->toThrow(ReleaseException::class);

    [$signedZip] = releaseFixture(['artisan' => 'hello'], '1.4.0', []);
    expect(fn () => (new ReleaseVerifier)->verify($signedZip, $signedZip.'.sig', '1.4.0'))
        ->toThrow(ReleaseException::class);
});

it('rejects signed traversal and private release content', function (): void {
    foreach (['../outside' => 'bad', 'modules/novfora/populate/secret.php' => 'bad'] as $name => $body) {
        [$zip] = releaseFixture([$name => $body]);
        expect(fn () => (new ReleaseVerifier)->verify($zip, $zip.'.sig', '1.4.0'))
            ->toThrow(ReleaseException::class);
    }
});

it('rejects caller supplied asset URLs before any network fetch', function (): void {
    [, $dir] = releaseFixture();
    Http::fake();
    $path = '/getnovfora/novfora/releases/download/v1.4.0/novfora-release.zip';
    foreach ([
        'https://github.com.evil.example'.$path,
        'https://github.com:443'.$path,
        'https://user@github.com'.$path,
        'https://github.com'.$path.'?alternate=1',
        'https://github.com'.$path.'#fragment',
    ] as $url) {
        expect(fn () => (new GitHubReleaseClient)->download([
            'version' => '1.4.0', 'tag' => 'v1.4.0',
            'archive_url' => $url,
            'signature_url' => 'https://github.com'.$path.'.sig',
        ], $dir.'/download'))->toThrow(ReleaseException::class);
    }
    Http::assertNothingSent();
});

it('refuses redirects carrying alternate ports or userinfo', function (): void {
    [, $dir] = releaseFixture();
    $zipUrl = 'https://github.com/getnovfora/novfora/releases/download/v1.4.0/novfora-release.zip';
    $sigUrl = $zipUrl.'.sig';
    foreach ([
        'https://release-assets.githubusercontent.com:443/asset',
        'https://user@release-assets.githubusercontent.com/asset',
    ] as $redirect) {
        Http::fake([$zipUrl => Http::response('', 302, ['Location' => $redirect])]);
        expect(fn () => (new GitHubReleaseClient)->download([
            'version' => '1.4.0', 'tag' => 'v1.4.0',
            'archive_url' => $zipUrl, 'signature_url' => $sigUrl,
        ], $dir.'/download'))->toThrow(ReleaseException::class, 'Unsafe release asset redirect');
    }
});
