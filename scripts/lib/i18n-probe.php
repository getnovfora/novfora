<?php

// SPDX-License-Identifier: Apache-2.0
//
// i18n resolution probe for the release acceptance test (scripts/verify-release.sh). Boots the EXTRACTED
// release tree and resolves a couple of representative translation keys (auth.login.*) WITHOUT a DB, HTTP,
// or a real APP_KEY, then asserts they return their localized strings.
//
// Why a direct resolve and not "GET /login": before install, RedirectIfNotInstalled sends /login (and every
// non-allowlisted path) to /install, so a cold artifact can never render the login form — and rendering it
// would also need a session key + a DB. Resolving the key directly is the robust cold proof that lang/ both
// SHIPPED and RESOLVES at runtime: if lang/ was dropped from the zip, Laravel returns the raw dotted key
// (which still contains "auth.login."), so this probe fails exactly on the Fix-2 deploy gap.
//
// Usage: php i18n-probe.php <extractedAppDir>

$base = rtrim($argv[1] ?? getcwd(), "/\\");

require $base.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $base.'/bootstrap/app.php';

try {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
} catch (\Throwable $e) {
    fwrite(STDOUT, 'BOOT_ERROR: '.$e->getMessage()."\n");
    fwrite(STDOUT, "I18N_PROBE_FAIL\n");
    exit(1);
}

// Pin the locale so the probe is deterministic regardless of the minimal .env the verify harness wrote.
app('translator')->setLocale('en');

$expected = [
    'auth.login.title'       => 'Sign in',
    'auth.login.email_label' => 'Email',
];

$ok = true;
foreach ($expected as $key => $want) {
    $got = __($key);
    // A resolved key returns its localized value; an UNresolved key is returned verbatim (still the dotted
    // token). Require BOTH the exact expected label AND no raw "auth.login." token leaking through.
    $resolved = ($got === $want) && (strpos((string) $got, 'auth.login.') === false);
    $ok = $ok && $resolved;
    fwrite(STDOUT, sprintf("%-26s => %-40s [%s]\n", $key, $got, $resolved ? 'OK' : 'UNRESOLVED'));
}

fwrite(STDOUT, ($ok ? 'I18N_PROBE_PASS' : 'I18N_PROBE_FAIL')."\n");

exit($ok ? 0 : 1);
