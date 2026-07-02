<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules\Packaging;

/**
 * Detached ed25519 package signatures (U17, ADR-0104, apex). A signed module ships a `module.sig` file at its
 * root whose content is the base64 ed25519 signature of the package's CANONICAL DIGEST — a sha-256 over the
 * path-sorted (name, length, bytes) of every OTHER file in the package (the same shape as
 * ModuleManager::packageHash, minus module.sig itself). Verification recomputes that digest from the staged
 * files and checks the signature against each configured trusted public key with libsodium's constant-time
 * verify; ANY trusted key that validates accepts. Missing sig / bad sig / no-trusted-key all fail closed.
 *
 * ext-sodium is bundled + enabled by default in PHP 8.3 (the floor). If it is somehow absent, verification
 * returns false for everything — the safe direction (every package reads as unsigned → rejected by policy).
 */
final class PackageSignature
{
    public const SIGNATURE_FILE = 'module.sig';

    /** True if the runtime can verify ed25519 signatures at all. */
    public static function available(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * The canonical 32-byte digest of a staged package directory (excluding module.sig). Deterministic and
     * order-independent: files are path-sorted, and each contributes name + length + content so neither a
     * rename nor a byte flip can preserve the digest.
     *
     * @throws PackageException
     */
    public static function digest(string $dir): string
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (! is_dir($dir)) {
            throw new PackageException(__('The package directory is missing.'));
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1)), '/');
            if ($rel === self::SIGNATURE_FILE) {
                continue; // the signature never signs itself
            }
            $files[] = $rel;
        }
        sort($files);

        $hash = hash_init('sha256');
        foreach ($files as $rel) {
            $path = $dir.'/'.$rel;
            $contents = (string) file_get_contents($path);
            hash_update($hash, $rel."\0".strlen($contents)."\0".$contents."\0");
        }

        return hash_final($hash, true); // raw 32 bytes
    }

    /**
     * Verify a staged package against a set of trusted base64 ed25519 public keys. Returns the matching key's
     * base64 (so the caller can record WHICH key signed it), or null if unsigned / tampered / untrusted.
     *
     * @param  list<string>  $trustedPublicKeysB64
     */
    public static function verify(string $dir, array $trustedPublicKeysB64): ?string
    {
        if (! self::available()) {
            return null;
        }

        $sigPath = rtrim(str_replace('\\', '/', $dir), '/').'/'.self::SIGNATURE_FILE;
        if (! is_file($sigPath)) {
            return null; // unsigned
        }

        $sig = base64_decode(trim((string) file_get_contents($sigPath)), true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null; // malformed signature
        }

        $digest = self::digest($dir);

        foreach ($trustedPublicKeysB64 as $keyB64) {
            $key = base64_decode(trim($keyB64), true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }
            try {
                if (sodium_crypto_sign_verify_detached($sig, $digest, $key)) {
                    return $keyB64;
                }
            } catch (\SodiumException) {
                // treat a malformed key as non-matching; keep checking the rest
            }
        }

        return null;
    }

    /**
     * Sign a staged package directory with a base64 ed25519 SECRET key, returning the base64 signature to
     * write as module.sig. This is the authoring recipe (used by the signing CLI + tests); the server only
     * ever VERIFIES.
     *
     * @throws PackageException
     */
    public static function sign(string $dir, string $secretKeyB64): string
    {
        if (! self::available()) {
            throw new PackageException(__('This runtime cannot create signatures (ext-sodium missing).'));
        }
        $secret = base64_decode(trim($secretKeyB64), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new PackageException(__('Invalid ed25519 secret key.'));
        }

        return base64_encode(sodium_crypto_sign_detached(self::digest($dir), $secret));
    }
}
