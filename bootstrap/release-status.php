<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/**
 * Maintenance-only owner status page. This file runs before Composer and Laravel, including during a
 * partial core swap. The capability was issued to a 2FA co-owner at request time and is short lived.
 * It permits only a read and a queued code rollback; cron performs every filesystem operation.
 *
 * @param  array<string,mixed>  $state
 */
function novfora_release_status(string $directory, array $state): never
{
    $token = $_COOKIE['novfora_release_recovery'] ?? null;
    if (! novfora_release_status_valid($state, $token)) {
        novfora_release_status_deny(404);
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'POST') {
        if (! is_string($_POST['recovery_token'] ?? null)
            || ! hash_equals($token, $_POST['recovery_token'])
            || ($_POST['action'] ?? null) !== 'rollback'
            || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 2048) {
            novfora_release_status_deny(403);
        }
        novfora_release_status_queue_rollback($directory, $token);
        $path = parse_url((string) ($state['app_url'] ?? ''), PHP_URL_PATH);
        $target = rtrim(is_string($path) ? $path : '', '/').'/admin/system/upgrade';
        http_response_code(303);
        header('Location: '.$target);
        header('Cache-Control: no-store');
        exit;
    }
    if ($method !== 'GET') {
        novfora_release_status_deny(405);
    }

    $phase = (string) ($state['phase'] ?? '');
    $labels = [
        'requested' => 'Queued', 'downloading' => 'Downloading', 'snapshotting' => 'Preparing backup',
        'applying' => 'Installing files', 'migrating' => 'Updating database', 'checking' => 'Checking site',
        'held' => 'Needs recovery', 'rollback_requested' => 'Rollback queued', 'rolling_back' => 'Rolling back',
        'failed' => 'Upgrade failed',
    ];
    $label = $labels[$phase] ?? 'Upgrade in progress';
    $version = htmlspecialchars((string) ($state['version'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $action = in_array($phase, ['applying', 'migrating', 'checking', 'held'], true)
        ? '<form method="post"><input type="hidden" name="action" value="rollback"><input type="hidden" name="recovery_token" value="'
            .htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'
            .'<button type="submit">Request code rollback</button></form>'
        : '';
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta http-equiv="refresh" content="5">'
        .'<meta name="viewport" content="width=device-width,initial-scale=1"><title>NovFora upgrade</title>'
        .'<style>body{font:1rem system-ui;max-width:42rem;margin:3rem auto;padding:0 1rem;color:#17212b}'
        .'button{padding:.7rem 1rem}small{color:#566}</style><main><h1>NovFora upgrade</h1><p>Release '
        .$version.'</p><p>Status: '.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</p>'
        .'<small>This page refreshes every five seconds.</small>'.$action.'</main></html>';
    exit;
}

/** @param array<string,mixed> $state */
function novfora_release_status_valid(array $state, mixed $token): bool
{
    return is_string($token) && strlen($token) === 64 && ctype_xdigit($token)
        && is_string($state['recovery_hash'] ?? null)
        && strlen($state['recovery_hash']) === 64
        && is_string($state['run_id'] ?? null)
        && ($state['recovery_run_id'] ?? null) === $state['run_id']
        && is_int($state['recovery_expires_at'] ?? null)
        && $state['recovery_expires_at'] >= time()
        && hash_equals($state['recovery_hash'], hash('sha256', $token));
}

function novfora_release_status_queue_rollback(string $directory, string $token): void
{
    $lock = @fopen($directory.'/run.lock', 'c');
    if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
        novfora_release_status_deny(503);
    }
    try {
        $raw = @file_get_contents($directory.'/state.json');
        $state = $raw === false ? null : json_decode($raw, true, 64);
        if (! is_array($state) || ! novfora_release_status_valid($state, $token)) {
            novfora_release_status_deny(403);
        }
        if (! in_array($state['phase'] ?? null, ['applying', 'migrating', 'checking', 'held', 'rollback_requested'], true)) {
            novfora_release_status_deny(409);
        }
        $state['phase'] = 'rollback_requested';
        $state['rollback_requested_at'] = gmdate(DATE_ATOM);
        $temp = tempnam($directory, '.state-');
        if ($temp === false) {
            novfora_release_status_deny(503);
        }
        try {
            chmod($temp, 0600);
            $payload = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $handle = fopen($temp, 'wb');
            if ($handle === false) {
                novfora_release_status_deny(503);
            }
            try {
                $offset = 0;
                while ($offset < strlen($payload)) {
                    $written = fwrite($handle, substr($payload, $offset));
                    if ($written === false || $written === 0) {
                        novfora_release_status_deny(503);
                    }
                    $offset += $written;
                }
                if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                    novfora_release_status_deny(503);
                }
            } finally {
                fclose($handle);
            }
            if (! rename($temp, $directory.'/state.json')) {
                novfora_release_status_deny(503);
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function novfora_release_status_deny(int $status): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo "Unavailable.\n";
    exit;
}
