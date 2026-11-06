<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Upgrade\ReleaseFilesystem;
use App\Upgrade\ReleaseRunState;
use App\Upgrade\ReleaseWorker;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/** @return array{dir:string,root:string,state:ReleaseRunState,run:array<string,mixed>,marker:string} */
function fileManagerRecoveryFixture(): array
{
    $dir = sys_get_temp_dir().'/novfora-file-manager-recovery-'.bin2hex(random_bytes(8));
    $root = $dir.'/live';
    $stage = $dir.'/stage';
    $backup = $dir.'/backup';
    mkdir($root.'/config', 0755, true);
    mkdir($stage.'/config', 0755, true);
    mkdir($backup, 0755, true);
    file_put_contents($root.'/artisan', 'old code');
    file_put_contents($stage.'/artisan', 'new code');
    file_put_contents($root.'/config/app.php', "<?php return ['version' => env('APP_VERSION', '1.3.0')];");
    file_put_contents($stage.'/config/app.php', "<?php return ['version' => env('APP_VERSION', '1.4.0')];");
    $files = [
        'artisan' => hash_file('sha256', $stage.'/artisan'),
        'config/app.php' => hash_file('sha256', $stage.'/config/app.php'),
    ];
    $filesystem = new ReleaseFilesystem;
    $plan = $filesystem->snapshot($root, $stage, $backup, $files);
    $filesystem->apply($root, $stage, $plan);
    file_put_contents($dir.'/data.zip', 'backup fixture');
    $run = [
        'phase' => 'held', 'run_id' => 'test-'.bin2hex(random_bytes(8)),
        'root' => $root, 'stage' => $stage, 'backup' => $backup, 'plan' => $plan,
        'data_backup' => $dir.'/data.zip', 'version' => '1.4.0',
        'app_url' => 'https://example.invalid/community',
        'recovery_hash' => hash('sha256', random_bytes(32)),
        'recovery_run_id' => 'expired', 'recovery_expires_at' => time() - 1,
    ];
    $state = new ReleaseRunState($root.'/storage/framework/release-upgrade');
    $state->locked(fn () => $state->write($run));
    $runDirectory = $state->directory.'/runs/'.$run['run_id'];
    mkdir($runDirectory, 0700, true);
    $marker = $runDirectory.'/request-rollback';

    return compact('dir', 'root', 'state', 'run', 'marker');
}

function fileManagerPreboot(string $root): Process
{
    $script = 'require '.var_export(base_path('bootstrap/release-upgrade.php'), true).';'
        .'$allowed=novfora_release_preboot('.var_export($root, true).',true,["artisan","schedule:run"]);'
        .'echo $allowed ? "DISPATCHED" : "NORMAL";';
    $process = new Process([PHP_BINARY, '-r', $script]);
    $process->run();

    return $process;
}

it('dispatches only a valid empty run-specific marker while held even after the browser capability expires', function () {
    $fixture = fileManagerRecoveryFixture();
    try {
        file_put_contents(dirname($fixture['marker']).'/worker.php', '<?php echo "WORKER";');
        expect(fileManagerPreboot($fixture['root'])->getExitCode())->toBe(75);
        file_put_contents($fixture['marker'], 'nonempty');
        expect(fileManagerPreboot($fixture['root'])->getExitCode())->toBe(75);
        unlink($fixture['marker']);
        file_put_contents($fixture['marker'], '');
        $result = fileManagerPreboot($fixture['root']);
        expect($result->getExitCode())->toBe(0)
            ->and($result->getOutput())->toBe('WORKERDISPATCHED');
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('consumes the File Manager marker under the run lock and restores code without a valid browser token', function () {
    $fixture = fileManagerRecoveryFixture();
    try {
        file_put_contents($fixture['marker'], '');
        $worker = new ReleaseWorker($fixture['state']->directory, fn (array $command): array => [
            'exit' => 0, 'output' => in_array('--check', $command, true) ? "Pending migrations 0\n" : "OK\n",
        ]);
        expect($worker->run()['phase'])->toBe('rolled_back')
            ->and(file_exists($fixture['marker']))->toBeFalse()
            ->and(file_get_contents($fixture['root'].'/artisan'))->toBe('old code')
            ->and($fixture['state']->read()['phase'])->toBe('rolled_back');
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});

it('refuses a symlink marker without changing held state', function () {
    $fixture = fileManagerRecoveryFixture();
    try {
        $target = $fixture['dir'].'/external';
        file_put_contents($target, '');
        symlink($target, $fixture['marker']);
        expect(fileManagerPreboot($fixture['root'])->getExitCode())->toBe(75);
        $worker = new ReleaseWorker($fixture['state']->directory);
        expect($worker->run()['phase'])->toBe('held')
            ->and(file_get_contents($fixture['root'].'/artisan'))->toBe('new code');
    } finally {
        File::deleteDirectory($fixture['dir']);
    }
});
