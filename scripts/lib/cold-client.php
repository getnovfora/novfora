<?php

// SPDX-License-Identifier: Apache-2.0
//
// Minimal HTTP client for the cold-boot acceptance test (scripts/verify-release.sh). Uses streams (no curl
// extension needed). GETs the URL WITHOUT following redirects, prints status + Location + a body snippet,
// and exits 0 only on a 3xx redirect to /install — the required pre-install behaviour for a fresh bundle.

$url = $argv[1] ?? 'http://127.0.0.1:8123/';

$status = null;
$location = null;
$body = false;

// Bounded wait: a healthy server answers on the first attempt; a refused connection retries cheaply; a
// server that ACCEPTS but never responds is capped so the acceptance test fails in ~30s rather than hanging
// for minutes (60 × a 5s read timeout was a 5-minute worst case that stalled CI).
$deadline = microtime(true) + 30.0;
for ($i = 0; $i < 60; $i++) {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'follow_location' => 0,
        'ignore_errors' => true, // capture 4xx/5xx bodies instead of throwing
        'timeout' => 3,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if (! empty($http_response_header)) {
        break; // got an HTTP response (any status)
    }
    if (microtime(true) >= $deadline) {
        break; // give up — the server never came up / never responded
    }
    usleep(200000); // 0.2s — wait for php -S to come up, then retry
}

$hdrs = $http_response_header ?? [];
foreach ($hdrs as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
        $status = (int) $m[1];
    }
    if (stripos($h, 'Location:') === 0) {
        $location = trim(substr($h, 9));
    }
}

$snippet = $body === false ? '' : substr(trim(preg_replace('/\s+/', ' ', strip_tags($body))), 0, 280);

fwrite(STDOUT, 'HTTP_STATUS='.($status ?? 'NONE')."\n");
fwrite(STDOUT, 'LOCATION='.($location ?? '')."\n");
fwrite(STDOUT, 'BODY_SNIPPET='.$snippet."\n");

$ok = $status !== null && $status >= 300 && $status < 400 && str_contains((string) $location, '/install');
fwrite(STDOUT, ($ok ? 'COLD_BOOT_PASS' : 'COLD_BOOT_FAIL')."\n");

exit($ok ? 0 : 1);
