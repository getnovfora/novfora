<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Registry;

/**
 * Detached ed25519 signing/verification of the registry feed bytes (Phase 4C, ADR-0113). The feed is a
 * STATIC file; its `.sig` is a detached signature over the EXACT feed bytes. A mirror can serve the bytes but
 * cannot alter, reorder, or truncate them without breaking the signature against the pinned root key.
 *
 * Same primitive as PackageSignature (bundled ext-sodium, constant-time), kept separate because the trust
 * root differs: this verifies the FEED against the pinned registry ROOT key; PackageSignature verifies a
 * MODULE against the admin-managed trusted-key registry.
 */
final class FeedSigner
{
    public static function available(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * Verify a detached signature over the exact feed bytes against a base64 ed25519 public key.
     * Returns false (never throws) on any malformed input or missing runtime support — fail closed.
     */
    public static function verify(string $bytes, string $signatureB64, string $publicKeyB64): bool
    {
        if (! self::available()) {
            return false;
        }

        $sig = base64_decode(trim($signatureB64), true);
        $key = base64_decode(trim($publicKeyB64), true);
        if ($sig === false || $key === false
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($sig, $bytes, $key);
        } catch (\SodiumException) {
            return false;
        }
    }

    /** Author-side only (the feed-signing script + tests): produce a base64 detached signature. */
    public static function sign(string $bytes, string $secretKeyB64): string
    {
        $secret = base64_decode(trim($secretKeyB64), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RegistryException('Invalid registry secret key.');
        }

        return base64_encode(sodium_crypto_sign_detached($bytes, $secret));
    }
}
