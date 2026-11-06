<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Usage: php sign-release.php <release.zip> <private-key-file> <key-id>
// The file may contain a raw 64-byte Ed25519 secret key or its base64 encoding.
$zip = $argv[1] ?? '';
$keyPath = $argv[2] ?? '';
$keyId = $argv[3] ?? '';
if (! function_exists('sodium_crypto_sign_detached')) {
    fwrite(STDERR, "FATAL: the sodium PHP extension is required to sign releases\n");
    exit(1);
}
if (! is_file($zip) || ! is_file($keyPath) || is_link($keyPath)
    || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $keyId) !== 1) {
    fwrite(STDERR, "FATAL: usage: sign-release.php <release.zip> <private-key-file> <key-id>\n");
    exit(1);
}

$key = file_get_contents($keyPath);
if ($key === false) {
    fwrite(STDERR, "FATAL: cannot read private key file\n");
    exit(1);
}
if (strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    $decoded = base64_decode(trim($key), true);
    $key = $decoded === false ? '' : $decoded;
}
if (strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "FATAL: private key file must hold a raw or base64 Ed25519 secret key\n");
    exit(1);
}

$bytes = file_get_contents($zip);
if ($bytes === false) {
    fwrite(STDERR, "FATAL: cannot read release zip\n");
    exit(1);
}

$signature = base64_encode(sodium_crypto_sign_detached($bytes, $key));
$payload = json_encode(['key_id' => $keyId, 'signature' => $signature], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
$temporary = $zip.'.sig.tmp';
if (file_put_contents($temporary, $payload) !== strlen($payload) || ! rename($temporary, $zip.'.sig')) {
    @unlink($temporary);
    fwrite(STDERR, "FATAL: cannot write detached signature\n");
    exit(1);
}

fwrite(STDOUT, "signed release: {$zip}.sig (key id: {$keyId})\n");
