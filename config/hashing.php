<?php

// SPDX-License-Identifier: Apache-2.0

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Hearth hashes passwords with argon2id by default (OWASP's recommended
    | memory-hard KDF). The "hashed" cast and Fortify both honour this driver, so
    | every password — registration, reset, factory — is argon2id. bcrypt stays
    | configured as a fallback for hosts whose PHP lacks libargon2.
    |
    | Supported: "bcrypt", "argon", "argon2id"
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536), // 64 MB
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],

    /*
    | Re-hash a password on login when the configured work factor has changed,
    | so upgrading cost (or migrating bcrypt -> argon2id) is transparent.
    */

    'rehash_on_login' => true,

];
