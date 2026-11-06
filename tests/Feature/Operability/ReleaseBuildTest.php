<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/** @return array{0:int,1:string} */
function releaseScript(array $args, array $env = []): array
{
    $command = implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY], $args)));
    $pipes = [];
    $process = proc_open($command.' 2>&1', [1 => ['pipe', 'w']], $pipes, base_path(), array_merge($_ENV, $env));
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start release script');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output ?: ''];
}

function releaseBuildFixture(): string
{
    $dir = sys_get_temp_dir().'/novfora-release-test-'.bin2hex(random_bytes(6));
    mkdir($dir.'/config', 0700, true);
    file_put_contents($dir.'/config/app.php', "<?php return ['version' => env('APP_VERSION', '2.3.4')];\n");
    file_put_contents($dir.'/readme.txt', 'hello release');

    return $dir;
}

it('writes a sorted manifest of exact staged file hashes using the source default version', function () {
    $dir = releaseBuildFixture();
    try {
        [$code, $output] = releaseScript([base_path('scripts/lib/release-manifest.php'), $dir], ['APP_VERSION' => '99.0.0']);
        expect($code)->toBe(0, $output);
        $manifest = json_decode(file_get_contents($dir.'/release-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['format'])->toBe(1)
            ->and($manifest['version'])->toBe('2.3.4')
            ->and($manifest['php'])->toBe('>=8.3')
            ->and($manifest['files'])->toBe([
                'config/app.php' => hash_file('sha256', $dir.'/config/app.php'),
                'readme.txt' => hash_file('sha256', $dir.'/readme.txt'),
            ]);
    } finally {
        @unlink($dir.'/release-manifest.json');
        @unlink($dir.'/readme.txt');
        @unlink($dir.'/config/app.php');
        @rmdir($dir.'/config');
        @rmdir($dir);
    }
});

it('refuses symlinks and a staged private Populate module', function () {
    $dir = releaseBuildFixture();
    try {
        symlink($dir.'/readme.txt', $dir.'/linked.txt');
        [$code] = releaseScript([base_path('scripts/lib/release-manifest.php'), $dir]);
        expect($code)->not->toBe(0);
        unlink($dir.'/linked.txt');

        mkdir($dir.'/modules/novfora/populate', 0700, true);
        file_put_contents($dir.'/modules/novfora/populate/module.json', '{}');
        [$code] = releaseScript([base_path('scripts/lib/release-manifest.php'), $dir]);
        expect($code)->not->toBe(0);
    } finally {
        @unlink($dir.'/modules/novfora/populate/module.json');
        @rmdir($dir.'/modules/novfora/populate');
        @rmdir($dir.'/modules/novfora');
        @rmdir($dir.'/modules');
        @unlink($dir.'/readme.txt');
        @unlink($dir.'/config/app.php');
        @rmdir($dir.'/config');
        @rmdir($dir);
    }
});

it('signs exact release bytes and fails verification after a byte changes', function () {
    $dir = releaseBuildFixture();
    try {
        $pair = sodium_crypto_sign_keypair();
        file_put_contents($dir.'/key', base64_encode(sodium_crypto_sign_secretkey($pair))."\n");
        file_put_contents($dir.'/novfora-release.zip', 'fixture zip bytes');
        [$code, $output] = releaseScript([base_path('scripts/sign-release.php'), $dir.'/novfora-release.zip', $dir.'/key', 'test-key']);
        expect($code)->toBe(0, $output);
        $sig = json_decode(file_get_contents($dir.'/novfora-release.zip.sig'), true, flags: JSON_THROW_ON_ERROR);
        expect(array_keys($sig))->toBe(['key_id', 'signature'])
            ->and($sig['key_id'])->toBe('test-key')
            ->and(sodium_crypto_sign_verify_detached(base64_decode($sig['signature'], true), 'fixture zip bytes', sodium_crypto_sign_publickey($pair)))->toBeTrue()
            ->and(sodium_crypto_sign_verify_detached(base64_decode($sig['signature'], true), 'fixture zip bytes!', sodium_crypto_sign_publickey($pair)))->toBeFalse();
    } finally {
        @unlink($dir.'/novfora-release.zip.sig');
        @unlink($dir.'/novfora-release.zip');
        @unlink($dir.'/key');
        @unlink($dir.'/readme.txt');
        @unlink($dir.'/config/app.php');
        @rmdir($dir.'/config');
        @rmdir($dir);
    }
});
