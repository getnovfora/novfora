<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules;

use App\Models\ModuleTrustKey;
use App\Modules\Packaging\PackageException;
use App\Support\Audit;
use Illuminate\Support\Collection;

/**
 * The trusted-key registry (U17, ADR-0104, apex) — the only writer of `module_trust_keys`. A signed package
 * is accepted only if one ENABLED key here verifies its signature. Adding/removing/toggling a key is an
 * admin-only, audited act. Public keys are validated to be real 32-byte ed25519 keys before storage.
 */
final class ModuleTrustKeys
{
    /**
     * The enabled trusted public keys (base64), for PackageSignature::verify.
     *
     * @return list<string>
     */
    public function enabledPublicKeys(): array
    {
        return ModuleTrustKey::query()->where('is_enabled', true)->pluck('public_key')->all();
    }

    /** @return Collection<int,ModuleTrustKey> */
    public function all(): Collection
    {
        return ModuleTrustKey::query()->orderBy('name')->get();
    }

    /**
     * Register a trusted key from its base64 public key. Idempotent on the fingerprint (re-adding an existing
     * key updates its name/enables it rather than duplicating).
     *
     * @throws PackageException
     */
    public function add(string $name, string $publicKeyB64): ModuleTrustKey
    {
        $name = trim($name);
        if ($name === '') {
            throw new PackageException(__('Give the trusted key a name.'));
        }

        $publicKeyB64 = trim($publicKeyB64);
        $raw = base64_decode($publicKeyB64, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new PackageException(__('That is not a valid ed25519 public key (base64 of 32 bytes).'));
        }

        $fingerprint = hash('sha256', $raw);
        $key = ModuleTrustKey::updateOrCreate(
            ['fingerprint' => $fingerprint],
            ['name' => mb_substr($name, 0, 100), 'public_key' => $publicKeyB64, 'is_enabled' => true],
        );

        Audit::log('module.trust_key.added', $key, ['name' => $key->name, 'fingerprint' => $fingerprint]);

        return $key;
    }

    public function setEnabled(ModuleTrustKey $key, bool $enabled): void
    {
        $key->update(['is_enabled' => $enabled]);
        Audit::log($enabled ? 'module.trust_key.enabled' : 'module.trust_key.disabled', $key, ['fingerprint' => $key->fingerprint]);
    }

    public function remove(ModuleTrustKey $key): void
    {
        Audit::log('module.trust_key.removed', $key, ['name' => $key->name, 'fingerprint' => $key->fingerprint]);
        $key->delete();
    }
}
