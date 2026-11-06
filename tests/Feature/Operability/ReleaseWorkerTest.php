<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Upgrade\ReleaseFilesystem;
use App\Upgrade\ReleaseRunState;
use App\Upgrade\ReleaseWorker;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/** @return array{dir:string,root:string,stage:string,backup:string,state:ReleaseRunState,run:array<string,mixed>} */
function frozenWorkerFixture(): array
{
    $dir = sys_get_temp_dir().'/novfora-worker-'.bin2hex(random_bytes(8));
    $root = $dir.'/live';
    $stage = $dir.'/stage';
    $backup = $dir.'/backup';
    foreach ([$root.'/config', $root.'/vendor', $stage.'/config', $stage.'/vendor', $backup] as $path) {
        mkdir($path, 0755, true);
    }
    file_put_contents($root.'/artisan', 'old');
    file_put_contents($root.'/vendor/probe.php', 'old vendor');
    file_put_contents($root.'/config/app.php', "<?php return ['version' => env('APP_VERSION', '1.3.0')];");
    file_put_contents($stage.'/artisan', 'new');
    file_put_contents($stage.'/vendor/probe.php', 'new vendor');
    file_put_contents($stage.'/config/app.php', "<?php return ['version' => env('APP_VERSION', '1.4.0')];");

    $files = [];
    foreach (['artisan', 'vendor/probe.php', 'config/app.php'] as $path) {
        $files[$path] = hash_file('sha256', $stage.'/'.$path);
    }
    $plan = (new ReleaseFilesystem)->snapshot($root, $stage, $backup, $files);
    $state = new ReleaseRunState($dir.'/private');
    $run = [
        'phase' => 'applying',
        'run_id' => 'test-'.bin2hex(random_bytes(8)),
        'root' => $root,
        'stage' => $stage,
        'backup' => $backup,
        'plan' => $plan,
        'data_backup' => $dir.'/data.zip',
        'app_url' => 'https://example.invalid/community',
        'version' => '1.4.0',
    ];
    file_put_contents($run['data_backup'], 'backup fixture');
    $state->locked(fn () => $state->write($run));

    return compact('dir', 'root', 'stage', 'backup', 'state', 'run');
}

/** @return array{exit:int,output:string} */
function passingReleaseCommand(array $command, string $cwd): array
{
    return ['exit' => 0, 'output' => in_array('--check', $command, true) ? "Pending migrations 0\n" : "OK\n"];
}

/** @return array{status:int,body:string} */
function passingReleaseHealth(string $url, string $token): array
{
    expect($url)->toBe('https://example.invalid/community/health')
        ->and(strlen($token))->toBe(64);

    return ['status' => 200, 'body' => json_encode([
        'version' => '1.4.0', 'installed' => true,
        'checks' => ['database' => ['ok' => true], 'cache' => ['ok' => true]],
        'schema' => ['pending' => false, 'upgrading' => false, 'stuck' => false],
        'restore' => ['stuck' => false],
    ], JSON_THROW_ON_ERROR)];
}

it('resumes an interrupted copy then validates migrations and installed code', function () {
    $fixture = frozenWorkerFixture();
    try {
        // Simulate a process killed between two atomic replacements.
        (new ReleaseFilesystem)->apply($fixture['root'], $fixture['stage'], array_slice($fixture['run']['plan'], 0, 1));
        $worker = new ReleaseWorker($fixture['state']->directory, 'passingReleaseCommand', 'passingReleaseHealth');
        expect($worker->run()['phase'])->toBe('complete')
            ->and(file_get_contents($fixture['root'].'/artisan'))->toBe('new')
            ->and(file_get_contents($fixture['root'].'/vendor/probe.php'))->toBe('new vendor')
            ->and($worker->run()['phase'])->toBe('complete');
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('runs the new staged artisan in a fresh PHP process rather than the loaded test application', function () {
    $fixture = frozenWorkerFixture();
    try {
        $script = <<<'PHP'
        <?php
        if (($argv[1] ?? '') === 'novfora:upgrade' && in_array('--check', $argv, true)) {
            echo "Pending migrations ........ 0\n";
        } elseif (($argv[1] ?? '') === 'novfora:upgrade') {
            file_put_contents(__DIR__.'/migration.marker', 'ran by new artisan');
        } else {
            echo "cache cleared\n";
        }
        PHP;
        file_put_contents($fixture['stage'].'/artisan', $script);
        $hashes = [];
        foreach (['artisan', 'vendor/probe.php', 'config/app.php'] as $path) {
            $hashes[$path] = hash_file('sha256', $fixture['stage'].'/'.$path);
        }
        $fixture['run']['plan'] = (new ReleaseFilesystem)->snapshot(
            $fixture['root'], $fixture['stage'], $fixture['backup'], $hashes,
        );
        $fixture['state']->locked(fn () => $fixture['state']->write($fixture['run']));

        expect((new ReleaseWorker($fixture['state']->directory, null, 'passingReleaseHealth'))->run()['phase'])->toBe('complete')
            ->and(file_get_contents($fixture['root'].'/migration.marker'))->toBe('ran by new artisan');
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('restores the exact old files and holds maintenance when an apply step fails', function () {
    $fixture = frozenWorkerFixture();
    try {
        unlink($fixture['stage'].'/vendor/probe.php');
        $commands = 0;
        $worker = new ReleaseWorker($fixture['state']->directory, function () use (&$commands) {
            $commands++;

            return ['exit' => 0, 'output' => ''];
        });
        $result = $worker->run();
        expect($result['phase'])->toBe('failed')
            ->and($result['error_step'])->toBe('apply')
            ->and($commands)->toBe(0)
            ->and(file_get_contents($fixture['root'].'/artisan'))->toBe('old')
            ->and(file_get_contents($fixture['root'].'/vendor/probe.php'))->toBe('old vendor');
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('keeps apply-failure recovery gated until old web opcodes can revalidate without resetting the deadline', function () {
    $fixture = frozenWorkerFixture();
    try {
        unlink($fixture['stage'].'/vendor/probe.php');
        $run = $fixture['run'];
        $run['opcache_revalidate_seconds'] = 120;
        $fixture['state']->locked(fn () => $fixture['state']->write($run));
        $worker = new ReleaseWorker($fixture['state']->directory, 'passingReleaseCommand');
        $waiting = $worker->run();
        expect($waiting['phase'])->toBe('rolling_back')
            ->and($waiting['rollback_reason'])->toBe('apply_failure')
            ->and($waiting['rollback_check_after'])->toBeGreaterThan(time() + 100)
            ->and(file_get_contents($fixture['root'].'/artisan'))->toBe('old');

        $again = $worker->run();
        expect($again['phase'])->toBe('rolling_back')
            ->and($again['rollback_check_after'])->toBe($waiting['rollback_check_after'])
            ->and($again['rollback_restored_at'])->toBe($waiting['rollback_restored_at']);
        $again['rollback_check_after'] = time() - 1;
        $fixture['state']->locked(fn () => $fixture['state']->write($again));
        expect($worker->run()['phase'])->toBe('failed');
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('holds new code after a migration failure and permits explicit code-only rollback', function () {
    $fixture = frozenWorkerFixture();
    try {
        $worker = new ReleaseWorker($fixture['state']->directory, function (array $command): array {
            return ['exit' => in_array('novfora:upgrade', $command, true) && ! in_array('--check', $command, true) ? 1 : 0,
                'output' => 'simulated migration error'];
        });
        $result = $worker->run();
        expect($result['phase'])->toBe('held')
            ->and($result['error_step'])->toBe('migrate')
            ->and(file_get_contents($fixture['root'].'/artisan'))->toBe('new')
            ->and($worker->run()['phase'])->toBe('held');

        $recovery = new ReleaseWorker($fixture['state']->directory, 'passingReleaseCommand', 'passingReleaseHealth');
        expect($recovery->rollback()['phase'])->toBe('rolled_back')
            ->and(file_get_contents($fixture['root'].'/artisan'))->toBe('old')
            ->and(file_get_contents($fixture['root'].'/vendor/probe.php'))->toBe('old vendor');
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('resumes an explicitly requested rollback after a killed partial restore', function () {
    $fixture = frozenWorkerFixture();
    try {
        $worker = new ReleaseWorker($fixture['state']->directory, 'passingReleaseCommand', 'passingReleaseHealth');
        expect($worker->run()['phase'])->toBe('complete');
        $run = $fixture['state']->read();
        $run['phase'] = 'rollback_requested';
        $fixture['state']->locked(fn () => $fixture['state']->write($run));
        // A previous process restored only artisan before dying.
        (new ReleaseFilesystem)->rollback($fixture['root'], $fixture['backup'], array_slice($run['plan'], 0, 1));
        expect($worker->run()['phase'])->toBe('rolled_back')
            ->and(file_get_contents($fixture['root'].'/artisan'))->toBe('old')
            ->and(file_get_contents($fixture['root'].'/vendor/probe.php'))->toBe('old vendor');
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('keeps explicit rollback gated until old web opcodes can revalidate', function () {
    $fixture = frozenWorkerFixture();
    try {
        $worker = new ReleaseWorker($fixture['state']->directory, 'passingReleaseCommand', 'passingReleaseHealth');
        expect($worker->run()['phase'])->toBe('complete');
        $run = $fixture['state']->read();
        $run['phase'] = 'rollback_requested';
        $run['opcache_revalidate_seconds'] = 120;
        $fixture['state']->locked(fn () => $fixture['state']->write($run));
        $waiting = $worker->run();
        expect($waiting['phase'])->toBe('rolling_back')
            ->and($waiting['rollback_check_after'])->toBeGreaterThan(time() + 100)
            ->and(file_get_contents($fixture['root'].'/artisan'))->toBe('old');
        $again = $worker->run();
        expect($again['rollback_check_after'])->toBe($waiting['rollback_check_after']);
        $again['rollback_check_after'] = time() - 1;
        $fixture['state']->locked(fn () => $fixture['state']->write($again));
        expect($worker->run()['phase'])->toBe('rolled_back');
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('updates and restores external served assets with the same frozen run', function () {
    $fixture = frozenWorkerFixture();
    try {
        $public = $fixture['dir'].'/web/community';
        $publicStage = $fixture['stage'].'/public';
        $publicBackup = $fixture['dir'].'/public-backup';
        mkdir($public.'/build', 0755, true);
        mkdir($publicStage.'/build', 0755, true);
        file_put_contents($public.'/build/app.js', 'old asset');
        file_put_contents($publicStage.'/build/app.js', 'new asset');
        $fixture['run']['public_dir'] = $public;
        $fixture['run']['public_stage'] = $publicStage;
        $fixture['run']['public_backup'] = $publicBackup;
        $fixture['run']['public_plan'] = (new ReleaseFilesystem(publicOnly: true))->snapshot(
            $public, $publicStage, $publicBackup, ['build/app.js' => hash_file('sha256', $publicStage.'/build/app.js')],
        );
        $fixture['state']->locked(fn () => $fixture['state']->write($fixture['run']));

        $worker = new ReleaseWorker($fixture['state']->directory, 'passingReleaseCommand', 'passingReleaseHealth');
        expect($worker->run()['phase'])->toBe('complete')
            ->and(file_get_contents($public.'/build/app.js'))->toBe('new asset');
        $run = $fixture['state']->read();
        $run['phase'] = 'rollback_requested';
        $fixture['state']->locked(fn () => $fixture['state']->write($run));
        expect($worker->run()['phase'])->toBe('rolled_back')
            ->and(file_get_contents($public.'/build/app.js'))->toBe('old asset');
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('keeps maintenance when health does not prove the selected version', function () {
    $fixture = frozenWorkerFixture();
    try {
        $worker = new ReleaseWorker($fixture['state']->directory, 'passingReleaseCommand', fn () => [
            'status' => 200,
            'body' => '{"version":"1.3.0","installed":true,"checks":{"database":{"ok":true}},"schema":{"pending":false}}',
        ]);
        $result = $worker->run();
        expect($result['phase'])->toBe('held')
            ->and($result['error_step'])->toBe('health')
            ->and(file_get_contents($fixture['root'].'/artisan'))->toBe('new');
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('defers health until the web opcode cache can revalidate and resumes next tick', function () {
    $fixture = frozenWorkerFixture();
    try {
        $run = $fixture['run'];
        $run['opcache_revalidate_seconds'] = 120;
        $fixture['state']->locked(fn () => $fixture['state']->write($run));
        $calls = 0;
        $worker = new ReleaseWorker($fixture['state']->directory, 'passingReleaseCommand', function (string $url, string $token) use (&$calls): array {
            $calls++;

            return passingReleaseHealth($url, $token);
        });
        $waiting = $worker->run();
        expect($waiting['phase'])->toBe('checking')
            ->and($waiting['checking_after'])->toBeGreaterThan(time() + 100)
            ->and($calls)->toBe(0);

        $waiting['checking_after'] = time() - 1; // Simulate the next cron tick after revalidation.
        $fixture['state']->locked(fn () => $fixture['state']->write($waiting));
        expect($worker->run()['phase'])->toBe('complete')
            ->and($calls)->toBe(1);
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('does not run commands when the operating-system lock is already held', function () {
    $fixture = frozenWorkerFixture();
    try {
        $fixture['state']->locked(function () use ($fixture) {
            expect(fn () => (new ReleaseWorker($fixture['state']->directory, 'passingReleaseCommand'))->run())
                ->toThrow(RuntimeException::class, 'Another upgrade operation');
        });
        expect(file_get_contents($fixture['root'].'/artisan'))->toBe('old');
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('defers the code swap while a pre-existing process holds the shared activity lease', function () {
    $fixture = frozenWorkerFixture();
    $lease = $fixture['state']->directory.'/activity.lock';
    $script = '$file=fopen('.var_export($lease, true).',"c");flock($file,LOCK_SH);echo "READY\\n";flush();sleep(20);';
    $holder = new Process([PHP_BINARY, '-r', $script]);
    try {
        $holder->start();
        $holder->waitUntil(fn ($type, $output) => str_contains($holder->getOutput(), 'READY'));
        $worker = new ReleaseWorker($fixture['state']->directory, 'passingReleaseCommand', 'passingReleaseHealth');
        expect($worker->run()['phase'])->toBe('applying')
            ->and(file_get_contents($fixture['root'].'/artisan'))->toBe('old');
        $holder->stop(1);
        expect($worker->run()['phase'])->toBe('complete')
            ->and(file_get_contents($fixture['root'].'/artisan'))->toBe('new');
    } finally {
        if ($holder->isRunning()) {
            $holder->stop(1);
        }
        File::deleteDirectory($fixture['dir']);
    }
});

it('allows only a matching one-use health probe past the preboot web gate', function () {
    $fixture = frozenWorkerFixture();
    try {
        $token = bin2hex(random_bytes(32));
        $gateState = new ReleaseRunState($fixture['root'].'/storage/framework/release-upgrade');
        $run = $fixture['run'];
        $run['phase'] = 'checking';
        $run['app_url'] = 'https://example.invalid/community';
        $run['health_probe_hash'] = hash('sha256', $token);
        $gateState->locked(fn () => $gateState->write($run));

        $probe = function (string $uri, string $method, string $header) use ($fixture): array {
            $bootstrap = var_export(base_path('bootstrap/release-upgrade.php'), true);
            $root = var_export($fixture['root'], true);
            $uri = var_export($uri, true);
            $method = var_export($method, true);
            $header = var_export($header, true);
            $script = "require {$bootstrap}; \$_SERVER['REQUEST_URI']={$uri}; \$_SERVER['REQUEST_METHOD']={$method}; \$_SERVER['HTTP_X_NOVFORA_UPGRADE_PROBE']={$header}; novfora_release_preboot({$root}, false); echo 'ALLOWED';";
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $output];
        };

        expect($probe('/community/health', 'GET', 'wrong'))->toBe([75, "NovFora is upgrading. Please retry shortly.\n"])
            ->and($probe('/community/elsewhere', 'GET', $token)[0])->toBe(75)
            ->and($probe('/community/health', 'POST', $token)[0])->toBe(75)
            ->and($probe('/community/health', 'GET', $token))->toBe([0, 'ALLOWED']);
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('keeps the normal scheduler alive until the destructive copy starts', function () {
    $fixture = frozenWorkerFixture();
    try {
        $gateState = new ReleaseRunState($fixture['root'].'/storage/framework/release-upgrade');
        $bootstrap = var_export(base_path('bootstrap/release-upgrade.php'), true);
        $root = var_export($fixture['root'], true);
        $probe = function (array $arguments) use ($bootstrap, $root): array {
            $args = var_export($arguments, true);
            $script = "require {$bootstrap}; novfora_release_preboot({$root}, true, {$args}); echo 'ALLOWED';";
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $output, $error];
        };

        foreach (['requested', 'downloading', 'snapshotting'] as $phase) {
            $run = $fixture['run'];
            $run['phase'] = $phase;
            $gateState->locked(fn () => $gateState->write($run));
            expect($probe(['artisan', 'schedule:run']))->toBe([0, 'ALLOWED', '']);
        }
        expect($probe(['artisan', 'novfora:release-upgrade', '--advance']))->toBe([0, 'ALLOWED', ''])
            ->and($probe(['artisan', 'migrate'])[0])->toBe(75);
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});
