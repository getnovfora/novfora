<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/**
 * Guard an in-place release swap before either autoloader is touched. This file is intentionally plain PHP.
 * Returns true only when it has dispatched the frozen cron worker; callers then exit without booting Laravel.
 * Invalid state fails closed. The normal scheduler may run during the pre-swap snapshot phase.
 *
 * @param  list<string>  $argv
 */
function novfora_release_preboot(string $root, bool $cli, array $argv = []): bool
{
    $directory = $root.'/storage/framework/release-upgrade';
    $file = $directory.'/state.json';
    if (! is_file($file)) {
        novfora_release_activity_shared($directory, null, $cli);

        return false;
    }

    $raw = file_get_contents($file);
    $state = $raw === false ? null : json_decode($raw, true, 64);
    if (! is_array($state) || ! isset($state['phase']) || ! is_string($state['phase'])) {
        novfora_release_unavailable($cli);
    }
    $phase = $state['phase'];

    // Run preparation in the cron process itself. A normal scheduler parent would retain its shared
    // code lease while waiting for a child that needs the exclusive snapshot lease.
    $command = $argv[1] ?? null;
    if ($cli && $command === 'schedule:run' && in_array($phase, ['requested', 'downloading', 'snapshotting'], true)) {
        $argv[1] = 'novfora:release-upgrade';
        $argv[] = '--advance';
        $GLOBALS['argv'] = $argv;
        $GLOBALS['argc'] = count($argv);
        $_SERVER['argv'] = $argv;
        $_SERVER['argc'] = count($argv);
        $command = 'novfora:release-upgrade';
    }
    if ($cli && in_array($phase, ['requested', 'downloading', 'snapshotting'], true)
        && $command === 'novfora:release-upgrade' && in_array('--advance', $argv, true)) {
        novfora_release_activity_shared($directory, $raw, true);

        return false; // Coordinator promotes its own SH to EX for snapshots.
    }

    if (! $cli && ! in_array($phase, ['idle', 'complete', 'failed', 'rolled_back'], true)) {
        $siteUrl = $state['app_url'] ?? null;
        $basePath = is_string($siteUrl) ? parse_url($siteUrl, PHP_URL_PATH) : null;
        $statusPath = rtrim(is_string($basePath) ? $basePath : '', '/').'/admin/system/upgrade';
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (is_string($requestPath) && $requestPath === $statusPath
            && ! str_contains($_SERVER['REQUEST_URI'] ?? '', '?')) {
            $statusHandler = $root.'/bootstrap/release-status.php';
            if (! is_file($statusHandler) || is_link($statusHandler)) {
                novfora_release_unavailable(false);
            }
            require_once $statusHandler;
            if (in_array($phase, ['requested', 'downloading'], true)
                && ! novfora_release_status_valid($state, $_COOKIE['novfora_release_recovery'] ?? null)) {
                novfora_release_activity_shared($directory, $raw, $cli);

                return false;
            }
            novfora_release_status($directory, $state);
        }
    }

    if (in_array($phase, ['idle', 'requested', 'downloading', 'ready', 'complete', 'failed', 'rolled_back'], true)) {
        novfora_release_activity_shared($directory, $raw, $cli);

        return false;
    }

    $runId = $state['run_id'] ?? null;
    if (! is_string($runId) || preg_match('/^[a-zA-Z0-9_-]{8,100}$/', $runId) !== 1) {
        novfora_release_unavailable($cli);
    }

    if (! $cli) {
        $probe = $_SERVER['HTTP_X_NOVFORA_UPGRADE_PROBE'] ?? null;
        $expectedHash = $state['health_probe_hash'] ?? null;
        $url = $state['app_url'] ?? null;
        $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;
        $healthPath = rtrim(is_string($path) ? $path : '', '/').'/health';
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if ($phase === 'checking' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
            && is_string($requestPath) && $requestPath === $healthPath
            && ! str_contains($_SERVER['REQUEST_URI'] ?? '', '?')
            && is_string($probe) && strlen($probe) === 64
            && ctype_xdigit($probe) && is_string($expectedHash)
            && hash_equals($expectedHash, hash('sha256', $probe))) {
            return false;
        }
        novfora_release_unavailable(false);
    }

    // The frozen worker is the only process permitted to start Laravel on mixed/new code.
    $child = getenv('NOVFORA_RELEASE_UPGRADE_CHILD');
    if (is_string($child) && hash_equals($runId, $child)
        && in_array($command, ['optimize:clear', 'route:clear', 'novfora:upgrade'], true)) {
        return false;
    }

    $manualRollback = false;
    if ($phase === 'held' && $command === 'schedule:run') {
        $runDirectory = $directory.'/runs/'.$runId;
        $marker = $runDirectory.'/request-rollback';
        $manualRollback = is_dir($runDirectory) && ! is_link($runDirectory)
            && is_file($marker) && ! is_link($marker) && filesize($marker) === 0;
    }
    if ((in_array($phase, ['applying', 'migrating', 'checking', 'rollback_requested', 'rolling_back'], true)
        || $manualRollback) && $command === 'schedule:run') {
        $worker = $directory.'/runs/'.$runId.'/worker.php';
        if (! is_file($worker) || is_link($worker)) {
            novfora_release_unavailable(true);
        }
        require $worker;

        return true;
    }

    novfora_release_unavailable(true);
}

/** Hold a shared process-lifetime lease before Composer boots. Recheck state to close the pre-read race. */
function novfora_release_activity_shared(string $directory, ?string $observed, bool $cli): void
{
    if (is_link($directory) || (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory))) {
        novfora_release_unavailable($cli);
    }
    $path = $directory.'/activity.lock';
    if (is_link($path)) {
        novfora_release_unavailable($cli);
    }
    $handle = @fopen($path, 'c');
    if ($handle === false) {
        novfora_release_unavailable($cli);
    }
    if (! flock($handle, LOCK_SH | LOCK_NB)) {
        fclose($handle);
        novfora_release_unavailable($cli);
    }
    $stateFile = $directory.'/state.json';
    $current = is_file($stateFile) ? @file_get_contents($stateFile) : null;
    if ($current !== $observed) {
        flock($handle, LOCK_UN);
        fclose($handle);
        novfora_release_unavailable($cli);
    }
    // This global remains live through application shutdown callbacks; PHP closes it at process exit.
    $GLOBALS['novfora_release_activity_lease'] = $handle;
    $GLOBALS['novfora_release_activity_path'] = $path;
}

/** Never reveal private state or paths from the pre-bootstrap boundary. */
function novfora_release_unavailable(bool $cli): never
{
    if ($cli) {
        fwrite(STDERR, "NovFora release maintenance is active.\n");
    } else {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        header('Retry-After: 30');
        echo "NovFora is upgrading. Please retry shortly.\n";
    }
    exit(75);
}
