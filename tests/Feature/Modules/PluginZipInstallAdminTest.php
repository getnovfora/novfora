<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\ModuleTrustKey;
use App\Modules\Packaging\PackageSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| U17 (ADR-0104) — the ACP install-from-zip + trust-key panel: authz (admin.access + staff-2FA re-asserted
| inside Livewire), the trusted-key registry, and an end-to-end signed upload through the component.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $base = sys_get_temp_dir().'/nvf-u17a-'.bin2hex(random_bytes(6));
    $this->u17Base = $base;
    config([
        'novfora.modules.path' => $base.'/modules',
        'novfora.modules.zip.staging_path' => $base.'/staging',
        'novfora.modules.zip.quarantine_path' => $base.'/quarantine',
        'novfora.modules.zip.allow_unsigned' => false,
    ]);
    File::ensureDirectoryExists($base.'/modules');
});

afterEach(function () {
    if (isset($this->u17Base)) {
        File::deleteDirectory($this->u17Base);
    }
});

function u17aAdmin()
{
    return Users::withTwoFactor(Users::inGroups(['admins']));
}

function u17aSignedZip(string $secretOut): string
{
    // Build a signed acme/widget zip with the caller's ed25519 secret key.
    $dir = sys_get_temp_dir().'/nvf-u17a-src-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/module.json', json_encode([
        'name' => 'Acme Widget', 'slug' => 'acme/widget', 'version' => '1.0.0', 'api_version' => '^1.0',
    ]));
    file_put_contents($dir.'/'.PackageSignature::SIGNATURE_FILE, PackageSignature::sign($dir, $secretOut));

    $zipPath = sys_get_temp_dir().'/nvf-u17a-pkg-'.bin2hex(random_bytes(6)).'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (['module.json', PackageSignature::SIGNATURE_FILE] as $f) {
        $zip->addFile($dir.'/'.$f, $f);
    }
    $zip->close();
    File::deleteDirectory($dir);

    return $zipPath;
}

it('gates the module-install panel on admin.access + staff 2fa', function () {
    Livewire::actingAs(Users::inGroups(['members']))->test('admin.module-install')->assertForbidden();
    Livewire::actingAs(Users::inGroups(['admins']))->test('admin.module-install')->assertForbidden(); // no 2FA
    Livewire::actingAs(u17aAdmin())->test('admin.module-install')->assertOk();
});

it('adds, toggles, and removes a trusted key (audited, validated)', function () {
    $pair = sodium_crypto_sign_keypair();
    $public = base64_encode(sodium_crypto_sign_publickey($pair));

    $c = Livewire::actingAs(u17aAdmin())->test('admin.module-install')
        ->set('keyName', 'Acme')->set('publicKey', $public)->call('addKey')->assertSet('error', null);

    $key = ModuleTrustKey::firstOrFail();
    expect($key->fingerprint)->toBe(hash('sha256', sodium_crypto_sign_publickey($pair)))
        ->and(AuditLog::where('action', 'module.trust_key.added')->exists())->toBeTrue();

    $c->call('toggleKey', $key->id);
    expect($key->refresh()->is_enabled)->toBeFalse();

    $c->call('removeKey', $key->id);
    expect(ModuleTrustKey::count())->toBe(0);
});

it('rejects a bogus public key with an inline error', function () {
    Livewire::actingAs(u17aAdmin())->test('admin.module-install')
        ->set('keyName', 'Bad')->set('publicKey', 'not-base64-key')->call('addKey')
        ->assertSet('error', fn ($e) => is_string($e) && $e !== '');

    expect(ModuleTrustKey::count())->toBe(0);
});

it('installs a signed upload end-to-end through the component', function () {
    $pair = sodium_crypto_sign_keypair();
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $secret = base64_encode(sodium_crypto_sign_secretkey($pair));

    $zipPath = u17aSignedZip($secret);
    $upload = UploadedFile::fake()->createWithContent('acme-widget.zip', (string) file_get_contents($zipPath));

    Livewire::actingAs(u17aAdmin())->test('admin.module-install')
        ->set('keyName', 'Acme')->set('publicKey', $public)->call('addKey')
        ->set('archive', $upload)->call('install')->assertSet('error', null);

    expect(Module::where('slug', 'acme/widget')->where('enabled', false)->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'module.zip_install.accepted')->exists())->toBeTrue();

    @unlink($zipPath);
});

it('surfaces an unsigned rejection as an inline error, not a 500', function () {
    $dir = sys_get_temp_dir().'/nvf-u17a-unsigned-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/module.json', json_encode([
        'name' => 'Acme', 'slug' => 'acme/widget', 'version' => '1.0.0', 'api_version' => '^1.0',
    ]));
    $zipPath = sys_get_temp_dir().'/nvf-u17a-unsigned-'.bin2hex(random_bytes(6)).'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile($dir.'/module.json', 'module.json');
    $zip->close();
    File::deleteDirectory($dir);

    Livewire::actingAs(u17aAdmin())->test('admin.module-install')
        ->set('archive', UploadedFile::fake()->createWithContent('x.zip', (string) file_get_contents($zipPath)))
        ->call('install')
        ->assertSet('error', fn ($e) => is_string($e) && $e !== '');

    expect(Module::where('slug', 'acme/widget')->exists())->toBeFalse();

    @unlink($zipPath);
});
