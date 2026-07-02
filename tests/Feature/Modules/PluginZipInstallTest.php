<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Module;
use App\Modules\ModuleTrustKeys;
use App\Modules\Packaging\ModuleInstaller;
use App\Modules\Packaging\PackageException;
use App\Modules\Packaging\PackageSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

/*
| U17 (ADR-0104) — install-from-zip + signature/trust gate, happy + adversarial paths. Every test uses an
| isolated temp modules/ + staging + quarantine dir (parallel-safe) so nothing touches the real modules/.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $base = sys_get_temp_dir().'/nvf-u17-'.bin2hex(random_bytes(6));
    $this->u17Base = $base;
    config([
        'novfora.modules.path' => $base.'/modules',
        'novfora.modules.zip.staging_path' => $base.'/staging',
        'novfora.modules.zip.quarantine_path' => $base.'/quarantine',
        'novfora.modules.zip.allow_unsigned' => false,
    ]);
    File::ensureDirectoryExists($base.'/modules');

    // A fresh trusted keypair per test; the public half is registered, the secret signs the fixtures.
    $pair = sodium_crypto_sign_keypair();
    $this->trustedPublic = base64_encode(sodium_crypto_sign_publickey($pair));
    $this->trustedSecret = base64_encode(sodium_crypto_sign_secretkey($pair));
});

afterEach(function () {
    if (isset($this->u17Base)) {
        File::deleteDirectory($this->u17Base);
    }
});

/**
 * Build a module .zip in a temp file. $files is relative-path => content and MUST include module.json.
 * If $secretKeyB64 is given, the package is signed (module.sig written from the dir digest). If
 * $tamperAfterSign is true, a file is mutated AFTER signing so the signature no longer matches.
 *
 * @param  array<string,string>  $files
 * @param  array<string,string|array{name:string,content:string,symlink:bool}>  $rawEntries  extra entries added verbatim to the zip
 */
function u17Zip(array $files, ?string $secretKeyB64 = null, bool $tamperAfterSign = false, array $rawEntries = []): string
{
    $dir = sys_get_temp_dir().'/nvf-u17-src-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($dir);
    foreach ($files as $rel => $content) {
        File::ensureDirectoryExists(dirname($dir.'/'.$rel));
        file_put_contents($dir.'/'.$rel, $content);
    }

    if ($secretKeyB64 !== null) {
        file_put_contents($dir.'/'.PackageSignature::SIGNATURE_FILE, PackageSignature::sign($dir, $secretKeyB64));
    }
    if ($tamperAfterSign) {
        // Add a file AFTER signing — the manifest stays valid, but the package digest no longer matches the
        // signature, so this must fail on the SIGNATURE check (not the manifest parse).
        File::ensureDirectoryExists($dir.'/src');
        file_put_contents($dir.'/src/tampered.php', "<?php\n// injected after signing");
    }

    $zipPath = sys_get_temp_dir().'/nvf-u17-pkg-'.bin2hex(random_bytes(6)).'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1)), '/');
            $zip->addFile($file->getPathname(), $rel);
        }
    }
    foreach ($rawEntries as $entry) {
        $name = is_array($entry) ? $entry['name'] : $entry;
        $content = is_array($entry) ? $entry['content'] : '';
        $zip->addFromString($name, $content);
        if (is_array($entry) && ($entry['symlink'] ?? false)) {
            // Mark the entry as a unix symlink in its external attributes (0120777 << 16). ArchiveGuard must
            // never materialize this as an actual symlink.
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0120777 << 16));
        }
    }
    $zip->close();
    File::deleteDirectory($dir);

    return $zipPath;
}

function u17Manifest(string $slug = 'acme/widget'): string
{
    return json_encode([
        'name' => 'Acme Widget',
        'slug' => $slug,
        'version' => '1.0.0',
        'api_version' => '^1.0',
        'description' => 'A test package.',
    ], JSON_PRETTY_PRINT);
}

it('installs a signed package from a trusted key, disabled', function () {
    app(ModuleTrustKeys::class)->add('Acme', $this->trustedPublic);
    $zip = u17Zip(['module.json' => u17Manifest(), 'src/Widget.php' => "<?php\n// widget"], $this->trustedSecret);

    $result = app(ModuleInstaller::class)->installFromZip($zip);

    expect($result['slug'])->toBe('acme/widget')
        ->and($result['trust'])->toBe('signed')
        ->and(Module::where('slug', 'acme/widget')->where('enabled', false)->exists())->toBeTrue()
        ->and(is_file(config('novfora.modules.path').'/acme/widget/module.json'))->toBeTrue();

    @unlink($zip);
});

it('rejects an unsigned package under the default policy and quarantines it', function () {
    $zip = u17Zip(['module.json' => u17Manifest()]);

    expect(fn () => app(ModuleInstaller::class)->installFromZip($zip))
        ->toThrow(PackageException::class);

    expect(Module::where('slug', 'acme/widget')->exists())->toBeFalse()
        ->and(is_dir(config('novfora.modules.path').'/acme/widget'))->toBeFalse();
    // The rejected archive is quarantined, not left in modules/.
    expect(count(File::glob(config('novfora.modules.zip.quarantine_path').'/*.zip')))->toBeGreaterThan(0);

    @unlink($zip);
});

it('installs an unsigned package only when the dev policy allows it', function () {
    config(['novfora.modules.zip.allow_unsigned' => true]);
    $zip = u17Zip(['module.json' => u17Manifest()]);

    $result = app(ModuleInstaller::class)->installFromZip($zip);

    expect($result['trust'])->toBe('unsigned')
        ->and(Module::where('slug', 'acme/widget')->exists())->toBeTrue();

    @unlink($zip);
});

it('rejects a package tampered after signing (bad signature) even under allow_unsigned', function () {
    config(['novfora.modules.zip.allow_unsigned' => true]); // still must reject — the sig is PRESENT but invalid
    app(ModuleTrustKeys::class)->add('Acme', $this->trustedPublic);
    $zip = u17Zip(['module.json' => u17Manifest(), 'src/W.php' => '<?php'], $this->trustedSecret, tamperAfterSign: true);

    expect(fn () => app(ModuleInstaller::class)->installFromZip($zip))->toThrow(PackageException::class);
    expect(Module::where('slug', 'acme/widget')->exists())->toBeFalse();

    @unlink($zip);
});

it('rejects a package signed by an untrusted key', function () {
    // Signed with a valid key, but that key is NOT registered as trusted.
    $stranger = sodium_crypto_sign_keypair();
    $strangerSecret = base64_encode(sodium_crypto_sign_secretkey($stranger));
    $zip = u17Zip(['module.json' => u17Manifest()], $strangerSecret);

    expect(fn () => app(ModuleInstaller::class)->installFromZip($zip))->toThrow(PackageException::class);
    expect(Module::where('slug', 'acme/widget')->exists())->toBeFalse();

    @unlink($zip);
});

it('rejects a path-traversal entry', function () {
    app(ModuleTrustKeys::class)->add('Acme', $this->trustedPublic);
    $zip = u17Zip(['module.json' => u17Manifest()], $this->trustedSecret, rawEntries: [
        ['name' => '../../evil.php', 'content' => '<?php', 'symlink' => false],
    ]);

    expect(fn () => app(ModuleInstaller::class)->installFromZip($zip))->toThrow(PackageException::class);
    expect(is_dir(config('novfora.modules.path').'/acme/widget'))->toBeFalse();

    @unlink($zip);
});

it('rejects an absolute-path entry', function () {
    $zip = u17Zip(['module.json' => u17Manifest()], $this->trustedSecret, rawEntries: [
        ['name' => '/etc/cron.d/evil', 'content' => 'x', 'symlink' => false],
    ]);

    expect(fn () => app(ModuleInstaller::class)->installFromZip($zip))->toThrow(PackageException::class);

    @unlink($zip);
});

it('never materializes a symlink entry as an actual symlink', function () {
    app(ModuleTrustKeys::class)->add('Acme', $this->trustedPublic);
    // A symlink-attributed entry named link.txt whose content is an escape path. It must land as a REGULAR
    // file containing that literal string — never a symlink pointing out of the module dir.
    $zip = u17Zip(['module.json' => u17Manifest(), 'link.txt' => '../../../../etc/passwd'], $this->trustedSecret, rawEntries: []);
    // Re-mark link.txt as a symlink in the archive.
    $z = new ZipArchive;
    $z->open($zip);
    $z->setExternalAttributesName('link.txt', ZipArchive::OPSYS_UNIX, (0120777 << 16));
    $z->close();

    app(ModuleInstaller::class)->installFromZip($zip);

    $landed = config('novfora.modules.path').'/acme/widget/link.txt';
    expect(is_file($landed))->toBeTrue()
        ->and(is_link($landed))->toBeFalse()
        ->and(file_get_contents($landed))->toBe('../../../../etc/passwd');

    @unlink($zip);
});

it('rejects a disallowed file type', function () {
    $zip = u17Zip(['module.json' => u17Manifest(), 'run.sh' => '#!/bin/sh'], $this->trustedSecret);

    expect(fn () => app(ModuleInstaller::class)->installFromZip($zip))->toThrow(PackageException::class);

    @unlink($zip);
});

it('rejects a zip with too many entries', function () {
    config(['novfora.modules.zip.max_entries' => 5]);
    $files = ['module.json' => u17Manifest()];
    foreach (range(1, 10) as $n) {
        $files["src/f{$n}.php"] = '<?php';
    }
    $zip = u17Zip($files, $this->trustedSecret);

    expect(fn () => app(ModuleInstaller::class)->installFromZip($zip))->toThrow(PackageException::class);

    @unlink($zip);
});

it('rejects a highly compressible zip bomb by ratio', function () {
    config(['novfora.modules.zip.max_file_bytes' => 10_000_000, 'novfora.modules.zip.max_compression_ratio' => 50]);
    // 2 MiB of zeros compresses to almost nothing → ratio blows the cap.
    $zip = u17Zip(['module.json' => u17Manifest(), 'assets/big.txt' => str_repeat("\0", 2_000_000)], $this->trustedSecret);

    expect(fn () => app(ModuleInstaller::class)->installFromZip($zip))->toThrow(PackageException::class);

    @unlink($zip);
});

it('refuses to install over an existing module without an upgrade confirmation', function () {
    app(ModuleTrustKeys::class)->add('Acme', $this->trustedPublic);
    $zip1 = u17Zip(['module.json' => u17Manifest()], $this->trustedSecret);
    app(ModuleInstaller::class)->installFromZip($zip1);

    $zip2 = u17Zip(['module.json' => u17Manifest()], $this->trustedSecret);
    expect(fn () => app(ModuleInstaller::class)->installFromZip($zip2, false))->toThrow(PackageException::class);

    @unlink($zip1);
    @unlink($zip2);
});

it('rejects a package whose manifest is invalid', function () {
    app(ModuleTrustKeys::class)->add('Acme', $this->trustedPublic);
    $zip = u17Zip(['module.json' => '{ not valid json'], $this->trustedSecret);

    expect(fn () => app(ModuleInstaller::class)->installFromZip($zip))->toThrow(PackageException::class);

    @unlink($zip);
});

it('rejects a package with no module.json at the root', function () {
    $zip = u17Zip(['src/Widget.php' => '<?php'], $this->trustedSecret);

    expect(fn () => app(ModuleInstaller::class)->installFromZip($zip))->toThrow(PackageException::class);

    @unlink($zip);
});
