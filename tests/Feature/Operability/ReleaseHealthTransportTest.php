<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('probes loopback health with cURL when URL streams are disabled and bounds the response', function (): void {
    if (! function_exists('curl_init')) {
        $this->markTestSkipped('cURL extension is needed for this transport regression.');
    }
    $directory = sys_get_temp_dir().'/novfora-health-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    file_put_contents($directory.'/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/redirect') {
    header('Location: /health', true, 302);
    exit;
}
if ($path === '/large') {
    echo str_repeat('x', 1_048_577);
    exit;
}
if (($_SERVER['HTTP_X_NOVFORA_UPGRADE_PROBE'] ?? '') !== 'test-probe') {
    http_response_code(403);
    exit;
}
header('Content-Type: application/json');
echo '{"ok":true}';
PHP);
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1);
    $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $directory, $directory.'/router.php']);
    try {
        $server->start();
        for ($i = 0; $i < 50; $i++) {
            $probe = @stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $error, 0.1);
            if ($probe !== false) {
                fclose($probe);
                break;
            }
            usleep(100_000);
        }
        expect($server->isRunning())->toBeTrue();
        foreach (['/health' => 200, '/redirect' => 302, '/large' => 0] as $path => $status) {
            $script = 'require '.var_export(base_path('vendor/autoload.php'), true).';'
                .'$worker=new \\App\\Upgrade\\ReleaseWorker('.var_export($directory, true).');'
                .'$method=new ReflectionMethod($worker,"requestHealth");'
                .'echo json_encode($method->invoke($worker,'.var_export('http://127.0.0.1:'.$port.$path, true).',"test-probe"));';
            $child = new Process([PHP_BINARY, '-d', 'allow_url_fopen=0', '-r', $script]);
            $child->run();
            expect($child->isSuccessful())->toBeTrue($child->getErrorOutput());
            $response = json_decode($child->getOutput(), true);
            expect($response['status'])->toBe($status);
            if ($path === '/health') {
                expect($response['body'])->toBe('{"ok":true}');
            }
            if ($path === '/large') {
                expect($response['body'])->toBe('');
            }
        }
    } finally {
        $server->stop(1);
        File::deleteDirectory($directory);
    }
});
