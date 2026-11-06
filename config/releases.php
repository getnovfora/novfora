<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

return [
    // Pin only independently distributed public keys here. Never fetch trust keys from a release.
    'trusted_keys' => [
        'core-2026-11' => 'u/c21DDgo9Nh0lW+a0Y/OQ7hFQDvImVUAZgzMgkhqhg=',
    ],
    'max_archive_bytes' => 268_435_456,
    'max_signature_bytes' => 4096,
    'max_entries' => 20_000,
    'max_file_bytes' => 134_217_728,
    'max_total_bytes' => 536_870_912,
    'max_compression_ratio' => 200,
];
