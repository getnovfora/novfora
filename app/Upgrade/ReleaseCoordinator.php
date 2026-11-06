<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Upgrade;

use App\Admin\AdminCoOwnerService;
use App\Backup\BackupService;
use App\Backup\RestoreState;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\Audit;
use App\Upgrade\Releases\GitHubReleaseClient;
use App\Upgrade\Releases\ReleaseVerifier;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Cookie;
use RuntimeException;
use Throwable;

/** Prepares a verified release while Laravel is intact; a frozen worker owns destructive operations. */
class ReleaseCoordinator
{
    public function __construct(
        private readonly ReleaseRunState $state,
        private readonly GitHubReleaseClient $channel,
        private readonly ReleaseVerifier $verifier,
        private readonly BackupService $backups,
    ) {}

    /** @return array<string,mixed>|null */
    public function check(bool $refresh = false): ?array
    {
        return $this->channel->latest($refresh);
    }

    /** @return array<string,mixed> */
    public function request(User $actor, ?string $publicDirectory = null): array
    {
        $this->authorize($actor);
        $release = $this->channel->latest(true);
        if ($release === null || ! version_compare($release['version'], (string) config('app.version'), '>')) {
            throw new RuntimeException('There is no newer signed stable release available.');
        }
        if (config('releases.trusted_keys', []) === []) {
            throw new RuntimeException('The core release signing key has not been provisioned for this installation.');
        }

        // A pinned APP_VERSION survives the code swap and makes the new /health report the old version.
        // Inspect both loaded/process values and the file itself: config:cache can hide .env until the
        // worker clears it, so config('app.version') alone cannot safely detect this override.
        $overrides = [getenv('APP_VERSION'), $_ENV['APP_VERSION'] ?? null, $_SERVER['APP_VERSION'] ?? null];
        $environment = (string) config('app.env', 'production');
        $environmentFiles = [base_path('.env'), app()->environmentFilePath()];
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $environment) === 1) {
            $environmentFiles[] = base_path('.env.'.$environment);
        }
        foreach (array_unique($environmentFiles) as $envFile) {
            if (! is_file($envFile)) {
                continue;
            }
            $contents = file_get_contents($envFile);
            if ($contents === false) {
                throw new RuntimeException('Cannot inspect APP_VERSION in the host environment file before upgrading.');
            }
            try {
                $parsed = Dotenv::parse($contents);
                $overrides[] = $parsed['APP_VERSION'] ?? null;
            } catch (Throwable) {
                throw new RuntimeException('Cannot inspect APP_VERSION in the host environment file before upgrading.');
            }
        }
        $source = file_get_contents(base_path('config/app.php'));
        if ($source === false || preg_match("/'version'\\s*=>\\s*env\\(\\s*'APP_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $source, $default) !== 1) {
            throw new RuntimeException('Cannot verify the installed source version before upgrading.');
        }
        if ((string) config('app.version') !== $default[1]) {
            $overrides[] = (string) config('app.version');
        }
        foreach ($overrides as $override) {
            if (is_string($override) && trim($override) !== '' && trim($override) !== $release['version']) {
                throw new RuntimeException('APP_VERSION is pinned to another version. Remove or update the override in the host environment or .env before upgrading.');
            }
        }

        // This request runs in the web PHP pool, whose OPcache is separate from cron's CLI pool.
        // A CLI opcache_invalidate() during the swap cannot invalidate indefinitely cached FPM bytecode.
        $opcacheFrequency = self::webOpcacheRevalidationSeconds(
            PHP_SAPI,
            extension_loaded('Zend OPcache') && (bool) ini_get('opcache.enable'),
            (bool) ini_get('opcache.validate_timestamps'),
            (int) ini_get('opcache.revalidate_freq'),
        );

        $run = $this->state->locked(function () use ($actor, $release, $publicDirectory, $opcacheFrequency): array {
            if ($this->state->isActive() || app(RestoreState::class)->shouldGateRequests()
                || app(SchemaState::class)->shouldGateRequests()) {
                throw new RuntimeException('An upgrade or restore is already pending.');
            }
            $public = realpath($publicDirectory ?? public_path());
            if ($public === false || ! is_file($public.'/index.php')) {
                throw new RuntimeException('The served public directory could not be verified.');
            }
            $run = [
                'phase' => 'requested', 'run_id' => bin2hex(random_bytes(16)),
                'version' => $release['version'], 'previous_version' => (string) config('app.version'),
                'release' => $release, 'actor_id' => (int) $actor->getKey(),
                'requested_at' => now()->toIso8601String(), 'root' => base_path(),
                'public_dir' => $public, 'app_url' => (string) config('app.url'),
                'request_max_execution_time' => max(0, (int) ini_get('max_execution_time')),
                'opcache_revalidate_seconds' => $opcacheFrequency,
            ];
            $token = bin2hex(random_bytes(32));
            $run['recovery_hash'] = hash('sha256', $token);
            $run['recovery_expires_at'] = time() + 7200;
            $run['recovery_run_id'] = $run['run_id'];
            $this->state->write($run);
            Audit::log('upgrade.release_requested', null, ['version' => $release['version'], 'run_id' => $run['run_id']]);

            $run['recovery_token'] = $token; // Only this return value contains plaintext; never persist it.

            return $run;
        });
        $urlPath = parse_url($run['app_url'], PHP_URL_PATH);
        $cookiePath = rtrim(is_string($urlPath) ? $urlPath : '', '/').'/';
        Cookie::queue(cookie('novfora_release_recovery', $run['recovery_token'], 120, $cookiePath,
            null, true, true, false, 'strict'));

        return $run;
    }

    /** The web SAPI's timestamp policy is the authority; CLI settings must not cause false refusals. */
    public static function webOpcacheRevalidationSeconds(string $sapi, bool $enabled, bool $validatesTimestamps, int $frequency): int
    {
        if (in_array($sapi, ['cli', 'phpdbg'], true) || ! $enabled) {
            return 0;
        }
        if (! $validatesTimestamps) {
            throw new RuntimeException('Web OPcache timestamp validation is disabled. Enable opcache.validate_timestamps or arrange a web PHP pool restart before in-place upgrades.');
        }
        if ($frequency < 0 || $frequency > PHP_INT_MAX - time() - 2) {
            throw new RuntimeException('Web OPcache revalidation interval is invalid for a safe in-place upgrade.');
        }

        return $frequency;
    }

    /** @return array<string,mixed> */
    public function advance(): array
    {
        return $this->state->locked(function (): array {
            $run = $this->state->read();
            if (! in_array($run['phase'] ?? '', ['requested', 'downloading', 'snapshotting'], true)) {
                return $run;
            }
            try {
                $actor = User::find($run['actor_id']);
                if (! $actor instanceof User) {
                    throw new RuntimeException('The requesting owner no longer exists.');
                }
                $this->authorize($actor); // Recheck revocation before the cron performs any operation.
                if (app(RestoreState::class)->shouldGateRequests()) {
                    throw new RuntimeException('A restore is pending; retry the upgrade after it finishes.');
                }
                if (! function_exists('proc_open')) {
                    throw new RuntimeException('This host cannot run the release recovery worker (proc_open is disabled).');
                }
                $directory = $this->state->directory.'/runs/'.$run['run_id'];
                if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    throw new RuntimeException('Cannot create the private release workspace.');
                }
                if ($run['phase'] !== 'snapshotting') {
                    $run['phase'] = 'downloading';
                    $this->state->write($run);
                    $zip = $this->channel->download($run['release'], $directory);
                    $stage = $directory.'/stage-'.bin2hex(random_bytes(6));
                    $manifest = $this->verifier->stage($zip, $stage, $run['version']);
                    $this->assertSupportedExtensionLayout($manifest['files'] ?? []);
                    if (! isset($manifest['files']['bootstrap/release-upgrade.php'], $manifest['files']['bootstrap/release-status.php'])) {
                        throw new RuntimeException('This release does not contain the resumable upgrade bootstrap.');
                    }
                    $run['stage'] = $stage;
                    $run['files'] = $manifest['files'];
                    $run['files']['release-manifest.json'] = hash_file('sha256', $stage.'/release-manifest.json');
                    // Gate new requests, then allow already-running PHP requests to drain before snapshots.
                    $run['snapshot_after'] = time() + max(60, (int) ($run['request_max_execution_time'] ?? 0));
                    $run['phase'] = 'snapshotting';
                    $this->state->write($run); // Standalone HTTP gate engages before data/code snapshots.
                }
                if (time() < (int) ($run['snapshot_after'] ?? PHP_INT_MAX)) {
                    return $run;
                }
                if (! $this->acquireExclusiveActivity()) {
                    return $run; // Existing HTTP/CLI work still owns old code; next cron tick retries.
                }
                try {
                    // Authorization may have changed while the request drained and EX was pending.
                    $actor = User::find($run['actor_id']);
                    if (! $actor instanceof User) {
                        throw new RuntimeException('The requesting owner no longer exists.');
                    }
                    $this->authorize($actor);
                    $files = new ReleaseFilesystem;
                    $obsolete = $this->obsoleteFiles($run['root'], $run['files']);
                    $this->preflightFilesystem($run, $files, $obsolete);
                    // Migration backups have their own retention policy. Pin this recovery point inside
                    // the private run so keep=1 (or a concurrent prune) cannot remove it mid-upgrade.
                    $dataBackup = $this->backups->create(0)->path;
                    $dataHash = hash_file('sha256', $dataBackup);
                    if ($dataHash === false) {
                        throw new RuntimeException('Cannot verify the pre-upgrade data backup.');
                    }
                    $run['data_backup'] = $directory.'/data-backup.zip';
                    $files->copyAtomic($dataBackup, $run['data_backup'], $dataHash);
                    $run['backup'] = $directory.'/code-backup';
                    $run['plan'] = $files->snapshot($run['root'], $run['stage'], $run['backup'], $run['files'],
                        $obsolete);
                    $this->preparePublicMirror($run, $directory, $obsolete);
                    $this->freezeWorker($directory);
                    $run['phase'] = 'applying';
                    $this->state->write($run);
                } finally {
                    $this->downgradeActivity(); // Protect this old Laravel process until it exits.
                }
            } catch (Throwable $e) {
                // Nothing destructive happens in this class. Release the window; backups remain available.
                $run['phase'] = 'failed';
                $run['error'] = mb_substr($e->getMessage(), 0, 500);
                $run['finished_at'] = now()->toIso8601String();
                $this->state->write($run);
                report($e);
            }

            return $run;
        });
    }

    public function authorize(User $actor): void
    {
        abort_unless($actor->canDo('admin.access', Scope::global())
            && $actor->two_factor_confirmed_at !== null
            && app(AdminCoOwnerService::class)->isCoOwner($actor), 403);
    }

    /** Convert this process's shared code lease (if any) to EX under run.lock. */
    private function acquireExclusiveActivity(): bool
    {
        $existing = $GLOBALS['novfora_release_activity_lease'] ?? null;
        if (is_resource($existing)) {
            if (($GLOBALS['novfora_release_activity_path'] ?? null) !== $this->state->directory.'/activity.lock') {
                throw new RuntimeException('The release activity lease belongs to another installation.');
            }
            if (flock($existing, LOCK_EX | LOCK_NB)) {
                return true;
            }
            if (! flock($existing, LOCK_SH | LOCK_NB)) {
                throw new RuntimeException('Could not restore the release activity lease.');
            }

            return false;
        }
        $path = $this->state->directory.'/activity.lock';
        if (is_link($path)) {
            throw new RuntimeException('Invalid release activity lock.');
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Could not open the release activity lock.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $GLOBALS['novfora_release_activity_lease'] = $handle;
        $GLOBALS['novfora_release_activity_path'] = $path;

        return true;
    }

    private function downgradeActivity(): void
    {
        $handle = $GLOBALS['novfora_release_activity_lease'] ?? null;
        if (! is_resource($handle) || ! flock($handle, LOCK_SH)) {
            throw new RuntimeException('Could not retain the release activity lease after snapshot.');
        }
    }

    /** A signed release must never claim success while silently omitting executable extension files. */
    private function assertSupportedExtensionLayout(array $files): void
    {
        $managed = new ReleaseFilesystem;
        foreach (array_keys($files) as $path) {
            if ((str_starts_with($path, 'modules/') || str_starts_with($path, 'themes/'))
                && ! $managed->isManaged($path)) {
                throw new RuntimeException('This release contains an unsupported module or theme layout; update the installer before applying it.');
            }
        }
    }

    /** Check swap and snapshot capacity before taking a database backup or touching any live code. */
    private function preflightFilesystem(array $run, ReleaseFilesystem $files, array $obsolete): void
    {
        $snapshotBytes = 0;
        $largestReplacement = 0;
        foreach (array_unique(array_merge(array_keys($run['files']), $obsolete)) as $relative) {
            if (! $files->isManaged($relative)) {
                continue;
            }
            $target = $files->path($run['root'], $relative);
            $parent = dirname($target);
            while (! is_dir($parent) && $parent !== dirname($parent)) {
                $parent = dirname($parent);
            }
            if (! is_writable($parent)) {
                throw new RuntimeException('The installed code directory is not writable: '.$relative);
            }
            if (is_file($target)) {
                $snapshotBytes += (int) filesize($target);
            }
            if (isset($run['files'][$relative])) {
                $staged = $files->path($run['stage'], $relative);
                if (! is_file($staged)) {
                    throw new RuntimeException('A staged release file is missing: '.$relative);
                }
                $largestReplacement = max($largestReplacement, (int) filesize($staged));
            }
        }
        $free = disk_free_space($this->state->directory);
        if ($free === false || $free < $snapshotBytes + $largestReplacement + 33_554_432) {
            throw new RuntimeException('Insufficient disk space for the code snapshot and replacement.');
        }
        $rootFree = disk_free_space($run['root']);
        if ($rootFree === false || $rootFree < $largestReplacement + 16_777_216) {
            throw new RuntimeException('Insufficient disk space to replace installed code.');
        }
        if (isset($run['public_dir']) && realpath($run['root'].'/public') !== realpath($run['public_dir'])
            && ! is_writable($run['public_dir'])) {
            throw new RuntimeException('The served public directory is not writable.');
        }
    }

    /** @param array<string,mixed> $run @param list<string> $obsolete */
    private function preparePublicMirror(array &$run, string $directory, array $obsolete): void
    {
        if (realpath($run['root'].'/public') === realpath($run['public_dir'])) {
            unset($run['public_dir']);

            return;
        }
        $mirror = new ReleaseFilesystem(publicOnly: true);
        $files = [];
        $retired = [];
        foreach ($run['files'] as $path => $hash) {
            if (! str_starts_with($path, 'public/')) {
                continue;
            }
            $relative = substr($path, 7);
            if (! $mirror->isManaged($relative)) {
                continue;
            }
            $top = explode('/', $relative)[0];
            if (is_link($run['public_dir'].'/'.$top)) {
                if (realpath($run['public_dir'].'/'.$top) !== realpath($run['root'].'/public/'.$top)) {
                    throw new RuntimeException('A public asset link points outside this installation.');
                }

                continue; // Canonical asset tree is already part of the main plan.
            }
            $files[$relative] = $hash;
        }
        foreach ($obsolete as $path) {
            if (! str_starts_with($path, 'public/')) {
                continue;
            }
            $relative = substr($path, 7);
            if (! $mirror->isManaged($relative)) {
                continue; // Keep host routing, uploads and non-release-owned public files.
            }
            $top = explode('/', $relative)[0];
            if (is_link($run['public_dir'].'/'.$top)) {
                if (realpath($run['public_dir'].'/'.$top) !== realpath($run['root'].'/public/'.$top)) {
                    throw new RuntimeException('A public asset link points outside this installation.');
                }

                continue; // The canonical asset tree is already covered by the main obsolete plan.
            }
            $retired[] = $relative;
        }
        $run['public_stage'] = $run['stage'].'/public';
        $run['public_backup'] = $directory.'/public-backup';
        $run['public_plan'] = $mirror->snapshot($run['public_dir'], $run['public_stage'], $run['public_backup'], $files, $retired);
    }

    /** @param array<string,string> $newFiles @return list<string> */
    private function obsoleteFiles(string $root, array $newFiles): array
    {
        $oldManifest = $root.'/release-manifest.json';
        if (! is_file($oldManifest)) {
            // Legacy releases did not ship a manifest. These directories are exclusively core code;
            // never infer ownership of uploads, custom themes/modules, or arbitrary public files.
            $oldFiles = [];
            foreach (['app', 'config', 'lang', 'resources', 'routes', 'vendor', 'database/migrations',
                'database/factories', 'database/seeders', 'public/build', 'public/icons',
                'modules/novfora/hello', 'modules/novfora/kudos', 'modules/novfora/qa',
                'themes/aurora', 'themes/nebula'] as $tree) {
                $directory = $root.'/'.$tree;
                if (! is_dir($directory) || is_link($directory)) {
                    continue;
                }
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isFile() || $file->isLink()) {
                        $oldFiles[] = $tree.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
                    }
                }
            }

            return array_values(array_diff($oldFiles, array_keys($newFiles)));
        }
        $manifest = json_decode((string) file_get_contents($oldManifest), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('The installed release manifest is invalid.');
        }

        return array_values(array_diff(array_keys($manifest['files']), array_keys($newFiles)));
    }

    private function freezeWorker(string $directory): void
    {
        foreach (['ReleaseWorker', 'ReleaseRunState', 'ReleaseFilesystem'] as $class) {
            if (! copy(__DIR__.'/'.$class.'.php', $directory.'/'.$class.'.php')) {
                throw new RuntimeException('Cannot freeze the recovery worker.');
            }
        }
        $stateDirectory = var_export($this->state->directory, true);
        $entry = "<?php\nrequire __DIR__.'/ReleaseRunState.php';\nrequire __DIR__.'/ReleaseFilesystem.php';\n"
            ."require __DIR__.'/ReleaseWorker.php';\n"
            .'$worker = new \\App\\Upgrade\\ReleaseWorker('.$stateDirectory.");\n"
            .'$worker->run();'."\n";
        if (file_put_contents($directory.'/worker.php', $entry) === false) {
            throw new RuntimeException('Cannot write the recovery entrypoint.');
        }
    }
}
