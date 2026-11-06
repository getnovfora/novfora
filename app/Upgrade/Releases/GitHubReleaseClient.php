<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Upgrade\Releases;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class GitHubReleaseClient
{
    private const API = 'https://api.github.com/repos/getnovfora/novfora/releases/latest';

    private const ZIP = 'novfora-release.zip';

    private const SIG = 'novfora-release.zip.sig';

    /** @return array{version:string,tag:string,archive_url:string,signature_url:string,published_at:string}|null */
    public function latest(bool $refresh = false): ?array
    {
        if ($refresh) {
            Cache::forget('novfora:release:latest');
        }

        return Cache::remember('novfora:release:latest', now()->addDay(), function (): ?array {
            $response = Http::acceptJson()->withOptions(['allow_redirects' => false])->timeout(10)->get(self::API);
            if (! $response->successful()) {
                throw new ReleaseException('Could not discover the latest published release.');
            }
            $data = $response->json();
            if (! is_array($data) || ($data['draft'] ?? true) !== false || ($data['prerelease'] ?? true) !== false) {
                return null;
            }
            $tag = $data['tag_name'] ?? null;
            if (! is_string($tag) || preg_match('/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $tag) !== 1) {
                return null;
            }
            $version = ltrim($tag, 'v');
            $assets = $data['assets'] ?? null;
            if (! is_array($assets)) {
                return null;
            }
            $urls = [];
            foreach ($assets as $asset) {
                if (! is_array($asset) || ! in_array($asset['name'] ?? null, [self::ZIP, self::SIG], true)) {
                    continue;
                }
                $name = $asset['name'];
                $url = $asset['browser_download_url'] ?? null;
                if (! is_string($url) || ! self::isReleaseUrl($url, $tag, $name)) {
                    return null;
                }
                $urls[$name] = $url;
            }
            if (! isset($urls[self::ZIP], $urls[self::SIG])) {
                return null;
            }
            $published = $data['published_at'] ?? null;
            if (! is_string($published) || strtotime($published) === false) {
                return null;
            }

            return ['version' => $version, 'tag' => $tag, 'archive_url' => $urls[self::ZIP],
                'signature_url' => $urls[self::SIG], 'published_at' => $published];
        });
    }

    /** @param array<string,mixed> $release */
    public function download(array $release, string $directory): string
    {
        $tag = $release['tag'] ?? null;
        $version = $release['version'] ?? null;
        if (! is_string($tag) || ! is_string($version) || ltrim($tag, 'v') !== $version
            || preg_match('/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $tag) !== 1) {
            throw new ReleaseException('Invalid release identity.');
        }
        foreach (['archive_url' => self::ZIP, 'signature_url' => self::SIG] as $key => $name) {
            if (! is_string($release[$key] ?? null) || ! self::isReleaseUrl($release[$key], $tag, $name)) {
                throw new ReleaseException('Release asset URL is outside the pinned GitHub repository.');
            }
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new ReleaseException('Could not create release staging directory.');
        }
        if (is_link($directory)) {
            throw new ReleaseException('Release staging directory is a symlink.');
        }
        $space = @disk_free_space($directory);
        if ($space !== false && $space < (int) config('releases.max_archive_bytes', 268_435_456) * 2) {
            throw new ReleaseException('Insufficient disk space for release staging.');
        }
        $zip = rtrim($directory, '/').'/'.Str::random(24).'.zip';
        $sig = $zip.'.sig';
        try {
            $this->fetch($release['archive_url'], $zip, (int) config('releases.max_archive_bytes'));
            $this->fetch($release['signature_url'], $sig, (int) config('releases.max_signature_bytes'));
            app(ReleaseVerifier::class)->verify($zip, $sig, $version);

            return $zip;
        } catch (\Throwable $e) {
            @unlink($zip);
            @unlink($sig);
            throw $e;
        }
    }

    private static function isReleaseUrl(string $url, string $tag, string $name): bool
    {
        $p = parse_url($url);

        return is_array($p) && ($p['scheme'] ?? null) === 'https' && ($p['host'] ?? null) === 'github.com'
            && ! isset($p['port']) && ! isset($p['user']) && ! isset($p['pass'])
            && ! isset($p['query']) && ! isset($p['fragment'])
            && ($p['path'] ?? null) === '/getnovfora/novfora/releases/download/'.rawurlencode($tag).'/'.$name;
    }

    private function fetch(string $url, string $path, int $limit): void
    {
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $response = Http::withOptions(['stream' => true, 'allow_redirects' => false])
                ->timeout(60)->get($url);
            if ($response->status() >= 300 && $response->status() < 400) {
                $next = $response->header('Location');
                $parts = parse_url($next);
                if ($redirect === 3 || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
                    || ! in_array($parts['host'] ?? null, ['release-assets.githubusercontent.com', 'github.com'], true)
                    || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
                    throw new ReleaseException('Unsafe release asset redirect.');
                }
                if ($parts['host'] === 'github.com' && ! str_starts_with($parts['path'] ?? '', '/getnovfora/novfora/releases/download/')) {
                    throw new ReleaseException('Unsafe release asset redirect.');
                }
                $url = $next;

                continue;
            }
            if (! $response->successful()) {
                throw new ReleaseException('Release asset download failed.');
            }
            $out = @fopen($path, 'xb');
            if ($out === false) {
                throw new ReleaseException('Could not create staged release asset.');
            }
            $count = 0;
            try {
                $body = $response->toPsrResponse()->getBody();
                while (! $body->eof()) {
                    $chunk = $body->read(262144);
                    $count += strlen($chunk);
                    if ($count > $limit || $chunk === '') {
                        throw new ReleaseException('Release asset exceeds its size limit or could not be read.');
                    }
                    $offset = 0;
                    while ($offset < strlen($chunk)) {
                        $written = fwrite($out, substr($chunk, $offset));
                        if ($written === false || $written === 0) {
                            throw new ReleaseException('Could not write staged release asset.');
                        }
                        $offset += $written;
                    }
                }
            } finally {
                fclose($out);
            }
            if ($count === 0) {
                throw new ReleaseException('Empty release asset.');
            }

            return;
        }
    }
}
