<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Upgrade;

use RuntimeException;
use Throwable;

/** Runs from a frozen copy before Composer or Laravel is loaded. */
final class ReleaseWorker
{
    private ReleaseRunState $state;

    private ReleaseFilesystem $files;

    private ReleaseFilesystem $publicFiles;

    /** @var null|callable(list<string>, string): array{exit:int,output:string} */
    private $commandRunner;

    /** @var null|callable(string, string): array{status:int,body:string} */
    private $healthProbe;

    public function __construct(string $stateDirectory, ?callable $commandRunner = null, ?callable $healthProbe = null)
    {
        $this->state = new ReleaseRunState($stateDirectory);
        $this->files = new ReleaseFilesystem;
        $this->publicFiles = new ReleaseFilesystem(publicOnly: true);
        $this->commandRunner = $commandRunner;
        $this->healthProbe = $healthProbe;
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        return $this->state->locked(function (): array {
            $run = $this->state->read();
            $this->validate($run);
            if (! in_array($run['phase'], ['held', 'rollback_requested', 'rolling_back', 'applying', 'migrating', 'checking'], true)) {
                return $run;
            }
            $activity = $this->exclusiveActivity();
            if ($activity === null) {
                return $run; // An admitted HTTP/CLI process still owns old code; cron retries next tick.
            }
            try {
                if ($run['phase'] === 'held') {
                    $runDirectory = $this->state->directory.'/runs/'.$run['run_id'];
                    $marker = $runDirectory.'/request-rollback';
                    if (! is_dir($runDirectory) || is_link($runDirectory)
                        || ! is_file($marker) || is_link($marker) || filesize($marker) !== 0) {
                        return $run;
                    }
                    // Persist intent before consuming the marker. A killed process resumes rollback next tick.
                    $run['phase'] = 'rollback_requested';
                    $run['rollback_requested_at'] = gmdate(DATE_ATOM);
                    $this->state->write($run);
                    @unlink($marker);
                }
                if (in_array($run['phase'], ['rollback_requested', 'rolling_back'], true)) {
                    return $this->rollbackLocked($run);
                }
                if (! in_array($run['phase'], ['applying', 'migrating', 'checking'], true)) {
                    return $run;
                }

                if ($run['phase'] === 'applying') {
                    try {
                        $this->files->apply($run['root'], $run['stage'], $run['plan']);
                        if (isset($run['public_plan'])) {
                            $this->publicFiles->apply($run['public_dir'], $run['public_stage'], $run['public_plan']);
                        }
                        // FPM may retain old opcodes until its next timestamp revalidation. CLI cache
                        // invalidation cannot clear a different PHP pool, so delay the HTTP health probe.
                        $interval = max(0, (int) ($run['opcache_revalidate_seconds'] ?? 0));
                        $run['checking_after'] = time() + ($interval > 0 ? $interval + 1 : 0);
                        $run['phase'] = 'migrating';
                        $run['updated_at'] = gmdate(DATE_ATOM);
                        $this->state->write($run);
                    } catch (Throwable $error) {
                        // Nothing reached the database yet. Restore every file that this run could have replaced.
                        $run['phase'] = 'rolling_back';
                        $run['rollback_reason'] = 'apply_failure';
                        $run['error_step'] = 'apply';
                        $run['error'] = substr($error->getMessage(), 0, 1000);
                        $run['updated_at'] = gmdate(DATE_ATOM);
                        $this->state->write($run);

                        return $this->rollbackLocked($run);
                    }
                }

                try {
                    if ($run['phase'] === 'migrating') {
                        $this->runChecked([PHP_BINARY, 'artisan', 'optimize:clear', '--no-ansi'], $run['root']);
                        $this->runChecked([PHP_BINARY, 'artisan', 'route:clear', '--no-ansi'], $run['root']);
                        $this->runChecked([PHP_BINARY, 'artisan', 'novfora:upgrade', '--no-ansi'], $run['root']);
                        $this->assertPendingNone($run['root']);
                        $this->assertInstalledFiles($run);
                        $run['phase'] = 'checking';
                        $run['updated_at'] = gmdate(DATE_ATOM);
                        $this->state->write($run);
                    }

                    // Re-check on every resumed health probe. A killed worker must not trust yesterday's
                    // successful check if the installed tree or schema has changed since then.
                    $this->assertPendingNone($run['root']);
                    $this->assertInstalledFiles($run);

                    if (time() < (int) ($run['checking_after'] ?? 0)) {
                        return $run; // Keep the gate up; the next cron tick resumes this health check.
                    }

                    // Persist the one-use capability before the HTTP request. After a killed worker, a new
                    // token replaces this hash and the next tick repeats the health check under maintenance.
                    $probe = bin2hex(random_bytes(32));
                    $run['health_probe_hash'] = hash('sha256', $probe);
                    $this->state->write($run);
                    $this->assertHealth($run, $probe);
                    unset($run['health_probe_hash']);
                    $run['phase'] = 'complete';
                    $run['updated_at'] = gmdate(DATE_ATOM);
                    $this->state->write($run);

                    return $run;
                } catch (Throwable $error) {
                    // Migrations may already have changed the database. Never roll them back automatically.
                    return $this->hold($run, $run['phase'] === 'checking' ? 'health' : 'migrate', $error);
                }
            } finally {
                flock($activity, LOCK_UN);
                fclose($activity);
            }
        });
    }

    /** Explicit code-only recovery; never reverses a database migration. @return array<string,mixed> */
    public function rollback(): array
    {
        return $this->state->locked(function (): array {
            $run = $this->state->read();
            $this->validate($run);
            $activity = $this->exclusiveActivity();
            if ($activity === null) {
                return $run;
            }
            try {
                return $this->rollbackLocked($run);
            } finally {
                flock($activity, LOCK_UN);
                fclose($activity);
            }
        });
    }

    /** @return resource|null */
    private function exclusiveActivity(): mixed
    {
        $path = $this->state->directory.'/activity.lock';
        if (is_link($path)) {
            throw new RuntimeException('Invalid release activity lock.');
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open release activity lock.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function rollbackLocked(array $run): array
    {
        if ($run['phase'] === 'rolled_back') {
            return $run;
        }
        if (! in_array($run['phase'], ['applying', 'migrating', 'checking', 'held', 'complete', 'rollback_requested', 'rolling_back'], true)) {
            throw new RuntimeException('This release run cannot be rolled back.');
        }
        $run['phase'] = 'rolling_back';
        $run['updated_at'] = gmdate(DATE_ATOM);
        $this->state->write($run);
        try {
            $this->ensureRestored($run);
            if (($run['rollback_reason'] ?? null) !== 'apply_failure' && ! ($run['rollback_checked'] ?? false)) {
                $this->runChecked([PHP_BINARY, 'artisan', 'optimize:clear', '--no-ansi'], $run['root']);
                $this->runChecked([PHP_BINARY, 'artisan', 'route:clear', '--no-ansi'], $run['root']);
                $this->assertPendingNone($run['root']);
                $run['rollback_checked'] = true;
                $this->state->write($run);
            }
            if (time() < (int) $run['rollback_check_after']) {
                return $run; // Restored files stay gated until web OPcache can revalidate.
            }
            $run['phase'] = ($run['rollback_reason'] ?? null) === 'apply_failure' ? 'failed' : 'rolled_back';
            $run['updated_at'] = gmdate(DATE_ATOM);
            $this->state->write($run);

            return $run;
        } catch (Throwable $error) {
            return $this->hold($run, 'rollback', $error);
        }
    }

    /** @param array<string,mixed> $run */
    private function ensureRestored(array &$run): void
    {
        if (isset($run['rollback_restored_at']) && $this->restoredMatches($run)) {
            return; // A previous tick completed restoration; never restart its OPcache timer.
        }
        $this->restoreFiles($run);
        if (! $this->restoredMatches($run)) {
            throw new RuntimeException('Restored code did not match the recovery snapshot.');
        }
        $interval = max(0, (int) ($run['opcache_revalidate_seconds'] ?? 0));
        $run['rollback_restored_at'] = time();
        $run['rollback_check_after'] = time() + ($interval > 0 ? $interval + 1 : 0);
        $run['rollback_checked'] = false;
        $this->state->write($run);
    }

    /** @param array<string,mixed> $run */
    private function restoredMatches(array $run): bool
    {
        foreach ([[$this->files, $run['root'], $run['plan']],
            [$this->publicFiles, $run['public_dir'] ?? null, $run['public_plan'] ?? []]] as [$files, $root, $plan]) {
            if (! is_string($root)) {
                continue;
            }
            foreach ($plan as $entry) {
                $target = $files->path($root, $entry['path']);
                if ($entry['existed'] ? (! is_file($target) || hash_file('sha256', $target) !== $entry['old_hash'])
                    : file_exists($target)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string,mixed> $run */
    private function restoreFiles(array $run): void
    {
        $failure = null;
        if (isset($run['public_plan'])) {
            try {
                $this->publicFiles->rollback($run['public_dir'], $run['public_backup'], $run['public_plan']);
            } catch (Throwable $error) {
                $failure = $error;
            }
        }
        try {
            $this->files->rollback($run['root'], $run['backup'], $run['plan']);
        } catch (Throwable $error) {
            $failure ??= $error;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @param array<string,mixed> $run */
    private function validate(array $run): void
    {
        if (! isset($run['run_id'], $run['phase'], $run['root'], $run['stage'], $run['backup'], $run['plan'], $run['data_backup'], $run['version'])
            || ! is_string($run['run_id']) || ! preg_match('/^[a-zA-Z0-9_-]{8,100}$/', $run['run_id'])
            || ! is_string($run['root']) || ! str_starts_with($run['root'], '/') || ! is_dir($run['root'])
            || ! is_string($run['stage']) || ! str_starts_with($run['stage'], '/')
            || (! in_array($run['phase'], ['rollback_requested', 'rolling_back', 'held', 'complete'], true) && ! is_dir($run['stage']))
            || ! is_string($run['backup']) || ! str_starts_with($run['backup'], '/') || ! is_dir($run['backup'])
            || ! is_array($run['plan']) || $run['plan'] === []
            || ! is_string($run['data_backup']) || ! is_file($run['data_backup']) || filesize($run['data_backup']) === 0
            || ! is_string($run['app_url'] ?? null) || $run['app_url'] === ''
            || ! is_string($run['version']) || ! preg_match('/^\d+\.\d+\.\d+$/', $run['version'])) {
            throw new RuntimeException('Invalid frozen release run state. Maintenance remains active.');
        }
        if (isset($run['public_plan']) && (! is_array($run['public_plan'])
            || ! isset($run['public_dir'], $run['public_stage'], $run['public_backup'])
            || ! is_dir($run['public_dir']) || ! is_dir($run['public_stage']) || ! is_dir($run['public_backup']))) {
            throw new RuntimeException('Invalid public release plan. Maintenance remains active.');
        }
    }

    /** @param array<string,mixed> $run */
    private function assertInstalledFiles(array $run): void
    {
        foreach ($run['plan'] as $entry) {
            if (! is_array($entry) || ! isset($entry['path']) || ! $this->files->isManaged($entry['path'])) {
                throw new RuntimeException('Invalid installed release plan.');
            }
            $target = $this->files->path($run['root'], $entry['path']);
            if ($entry['new_hash'] === null ? file_exists($target)
                : (! is_file($target) || hash_file('sha256', $target) !== $entry['new_hash'])) {
                throw new RuntimeException('Installed release file failed integrity check: '.$entry['path']);
            }
        }
        if (isset($run['public_plan'])) {
            foreach ($run['public_plan'] as $entry) {
                if (! is_array($entry) || ! isset($entry['path']) || ! $this->publicFiles->isManaged($entry['path'])) {
                    throw new RuntimeException('Invalid public release plan.');
                }
                $target = $this->publicFiles->path($run['public_dir'], $entry['path']);
                if ($entry['new_hash'] === null ? file_exists($target)
                    : (! is_file($target) || hash_file('sha256', $target) !== $entry['new_hash'])) {
                    throw new RuntimeException('Served asset failed integrity check: '.$entry['path']);
                }
            }
        }
        $source = file_get_contents($run['root'].'/config/app.php');
        if ($source === false || preg_match("/'version'\\s*=>\\s*env\\(\\s*'APP_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $source, $match) !== 1
            || $match[1] !== $run['version']) {
            throw new RuntimeException('Installed code version does not match the selected release.');
        }
    }

    private function assertPendingNone(string $root): void
    {
        $result = $this->runChecked([PHP_BINARY, 'artisan', 'novfora:upgrade', '--check', '--no-ansi'], $root);
        if (preg_match('/^\s*Pending migrations\b[^\r\n]*?\b0\s*$/mi', $result) !== 1) {
            throw new RuntimeException('Migration status did not confirm zero pending migrations.');
        }
    }

    /** @param array<string,mixed> $run */
    private function assertHealth(array $run, string $token): void
    {
        $base = $run['app_url'];
        $parts = parse_url($base);
        $loopbackHttp = is_array($parts) && ($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
        if (! is_array($parts) || (($parts['scheme'] ?? '') !== 'https' && ! $loopbackHttp) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Invalid configured health URL.');
        }
        $url = rtrim($base, '/').'/health';
        $response = $this->healthProbe !== null
            ? ($this->healthProbe)($url, $token)
            : $this->requestHealth($url, $token);
        if ($response['status'] !== 200) {
            throw new RuntimeException('The upgraded site health check did not return HTTP 200.');
        }
        $health = json_decode($response['body'], true);
        if (! is_array($health) || ($health['version'] ?? null) !== $run['version']
            || ($health['installed'] ?? null) !== true
            || ($health['checks']['database']['ok'] ?? null) !== true
            || ($health['checks']['cache']['ok'] ?? null) !== true
            || ($health['schema']['pending'] ?? true) !== false
            || ($health['schema']['upgrading'] ?? true) !== false
            || ($health['schema']['stuck'] ?? true) !== false
            || ($health['restore']['stuck'] ?? true) !== false) {
            throw new RuntimeException('The upgraded site health response did not validate.');
        }
    }

    /** @return array{status:int,body:string} */
    private function requestHealth(string $url, string $token): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "X-NovFora-Upgrade-Probe: {$token}\r\nAccept: application/json\r\n",
            'timeout' => 20,
            'follow_location' => 0,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $headers = $http_response_header;
        $status = isset($headers[0]) && preg_match('~^HTTP/\S+ (\d{3})(?:\s|$)~', $headers[0], $matches) === 1
            ? (int) $matches[1] : 0;

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }

    /** @param list<string> $command */
    private function runChecked(array $command, string $cwd): string
    {
        $result = $this->commandRunner !== null
            ? ($this->commandRunner)($command, $cwd)
            : $this->process($command, $cwd);
        if (! isset($result['exit'], $result['output'])
            || $result['exit'] !== 0 || ! is_string($result['output'])) {
            throw new RuntimeException('Release command failed: '.implode(' ', array_slice($command, 1)).' '.substr((string) ($result['output'] ?? ''), -600));
        }

        return $result['output'];
    }

    /** Bounded, shell-free child process. @param list<string> $command @return array{exit:int,output:string} */
    private function process(array $command, string $cwd): array
    {
        $env = getenv();
        $active = $this->state->read();
        $env['NOVFORA_RELEASE_UPGRADE_CHILD'] = (string) $active['run_id'];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start release command.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + 600;
        try {
            do {
                foreach ([1, 2] as $index) {
                    $chunk = stream_get_contents($pipes[$index]);
                    if ($chunk !== false && $chunk !== '') {
                        $output = substr($output.$chunk, -8192);
                    }
                }
                $status = proc_get_status($process);
                if (! $status['running']) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process);
                    throw new RuntimeException('Release command exceeded its 10-minute limit.');
                }
                usleep(100000);
            } while (true);
            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index]);
                if ($chunk !== false) {
                    $output = substr($output.$chunk, -8192);
                }
            }

            return ['exit' => $status['exitcode'], 'output' => $output];
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function hold(array $run, string $step, Throwable $error): array
    {
        $run['phase'] = 'held';
        $run['error_step'] = $step;
        $run['error'] = substr($error->getMessage(), 0, 1000);
        $run['updated_at'] = gmdate(DATE_ATOM);
        $this->state->write($run);

        return $run;
    }
}
