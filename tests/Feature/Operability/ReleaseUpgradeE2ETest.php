<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Upgrade\ReleaseFilesystem;
use App\Upgrade\ReleaseRunState;
use App\Upgrade\ReleaseWorker;
use Illuminate\Support\Facades\File;

it('keeps HTTP in maintenance until a fresh child migrates and a real health probe validates', function () {
    $directory = sys_get_temp_dir().'/novfora-release-e2e-'.bin2hex(random_bytes(8));
    $root = $directory.'/live';
    $stage = $directory.'/stage';
    $backup = $directory.'/backup';
    foreach ([$root.'/config', $stage.'/config', $backup] as $path) {
        mkdir($path, 0755, true);
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
    expect(is_resource($socket))->toBeTrue();
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1);

    $bootstrap = var_export(base_path('bootstrap/release-upgrade.php'), true);
    $liveRoot = var_export($root, true);
    $router = $directory.'/router.php';
    file_put_contents($router, "<?php\nrequire {$bootstrap};\nnovfora_release_preboot({$liveRoot}, false);\nheader('Content-Type: application/json');\necho json_encode(['version'=>'1.4.0','installed'=>true,'checks'=>['database'=>['ok'=>true],'cache'=>['ok'=>true]],'schema'=>['pending'=>false,'upgrading'=>false,'stuck'=>false],'restore'=>['stuck'=>false]]);\n");
    file_put_contents($root.'/artisan', 'old');
    file_put_contents($root.'/config/app.php', "<?php return ['version' => env('APP_VERSION', '1.3.0')];");
    file_put_contents($stage.'/config/app.php', "<?php return ['version' => env('APP_VERSION', '1.4.0')];");
    file_put_contents($stage.'/artisan', <<<'PHP'
        <?php
        if (($argv[1] ?? '') === 'novfora:upgrade' && in_array('--check', $argv, true)) {
            echo "Pending migrations ........ 0\n";
        } elseif (($argv[1] ?? '') === 'novfora:upgrade') {
            file_put_contents(__DIR__.'/migrated', 'yes');
        }
        PHP);
    $hashes = [
        'artisan' => hash_file('sha256', $stage.'/artisan'),
        'config/app.php' => hash_file('sha256', $stage.'/config/app.php'),
    ];
    $plan = (new ReleaseFilesystem)->snapshot($root, $stage, $backup, $hashes);
    $dataBackup = $directory.'/data.zip';
    file_put_contents($dataBackup, 'backup fixture');
    $state = new ReleaseRunState($root.'/storage/framework/release-upgrade');
    $run = [
        'phase' => 'applying', 'run_id' => bin2hex(random_bytes(16)),
        'root' => $root, 'stage' => $stage, 'backup' => $backup,
        'plan' => $plan, 'data_backup' => $dataBackup,
        'version' => '1.4.0', 'app_url' => "http://127.0.0.1:{$port}/community",
    ];
    $state->locked(fn () => $state->write($run));

    $pipes = [];
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    expect(is_resource($server))->toBeTrue();
    fclose($pipes[0]);
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(100000);
        }
        expect($ready)->toBeTrue();
        $url = "http://127.0.0.1:{$port}/community/health";
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 3]]);
        expect(file_get_contents($url, false, $context))->toContain('NovFora is upgrading');

        $result = (new ReleaseWorker($state->directory))->run();
        expect($result['phase'])->toBe('complete')
            ->and(file_get_contents($root.'/migrated'))->toBe('yes')
            ->and(json_decode(file_get_contents($url), true)['version'])->toBe('1.4.0');
    } finally {
        proc_terminate($server);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($server);
        File::deleteDirectory($directory);
    }
});
