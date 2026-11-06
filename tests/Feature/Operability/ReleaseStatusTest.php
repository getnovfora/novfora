<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->statusRoot = sys_get_temp_dir().'/novfora-status-'.bin2hex(random_bytes(8));
    $this->statusDirectory = $this->statusRoot.'/storage/framework/release-upgrade';
    mkdir($this->statusDirectory, 0700, true);
    mkdir($this->statusRoot.'/bootstrap', 0700, true);
    copy(base_path('bootstrap/release-status.php'), $this->statusRoot.'/bootstrap/release-status.php');
    $this->statusToken = bin2hex(random_bytes(32));
    $this->statusState = [
        'phase' => 'applying', 'run_id' => 'run-'.bin2hex(random_bytes(8)),
        'version' => '1.4.0', 'app_url' => 'https://example.test/community',
        'recovery_hash' => hash('sha256', $this->statusToken),
        'recovery_run_id' => null, 'recovery_expires_at' => time() + 7200,
        'root' => '/private/never-display', 'plan' => [['path' => 'app/Core.php']],
    ];
    $this->statusState['recovery_run_id'] = $this->statusState['run_id'];
    file_put_contents($this->statusDirectory.'/state.json', json_encode($this->statusState));
});

afterEach(function (): void {
    File::deleteDirectory($this->statusRoot);
});

/** Run the actual preboot dispatcher without Laravel in a separate PHP process. */
function statusProcess(string $root, string $token, string $uri, string $method = 'GET', ?string $formToken = null): Process
{
    $preboot = base_path('bootstrap/release-upgrade.php');
    $script = '$_SERVER["REQUEST_URI"]='.var_export($uri, true).';'
        .'$_SERVER["REQUEST_METHOD"]='.var_export($method, true).';'
        .'$_COOKIE["novfora_release_recovery"]='.var_export($token, true).';'
        .'$_POST["action"]="rollback";'
        .'$_POST["recovery_token"]='.var_export($formToken, true).';'
        .'require '.var_export($preboot, true).';'
        .'$result=novfora_release_preboot('.var_export($root, true).',false);'
        .'echo $result ? "gated" : "normal";';
    $process = new Process([PHP_BINARY, '-r', $script]);
    $process->run();

    return $process;
}

it('shows only safe status to the valid run capability and leaves state unchanged on GET', function (): void {
    $before = file_get_contents($this->statusDirectory.'/state.json');
    $result = statusProcess($this->statusRoot, $this->statusToken, '/community/admin/system/upgrade');
    expect($result->getOutput())->toContain('Status: Installing files')
        ->not->toContain('/private/never-display')
        ->and(file_get_contents($this->statusDirectory.'/state.json'))->toBe($before);
});

it('serves capability status during queued and download phases before the maintenance gate starts', function (): void {
    foreach (['requested' => 'Queued', 'downloading' => 'Downloading'] as $phase => $label) {
        $state = [...$this->statusState, 'phase' => $phase];
        file_put_contents($this->statusDirectory.'/state.json', json_encode($state));
        expect(statusProcess($this->statusRoot, $this->statusToken, '/community/admin/system/upgrade')->getOutput())
            ->toContain('Status: '.$label);
        expect(statusProcess($this->statusRoot, '', '/community/admin/system/upgrade')->getOutput())
            ->toBe('normal');
    }
});

it('refuses a missing, forged, expired, mismatched-run or wrong-path capability', function (): void {
    foreach (['', bin2hex(random_bytes(32))] as $bad) {
        expect(statusProcess($this->statusRoot, $bad, '/community/admin/system/upgrade')->getOutput())
            ->toBe("Unavailable.\n");
    }
    $expired = $this->statusState;
    $expired['recovery_expires_at'] = time() - 1;
    file_put_contents($this->statusDirectory.'/state.json', json_encode($expired));
    expect(statusProcess($this->statusRoot, $this->statusToken, '/community/admin/system/upgrade')->getOutput())
        ->toBe("Unavailable.\n");
    $wrongRun = $this->statusState;
    $wrongRun['recovery_run_id'] = 'another-run';
    file_put_contents($this->statusDirectory.'/state.json', json_encode($wrongRun));
    expect(statusProcess($this->statusRoot, $this->statusToken, '/community/admin/system/upgrade')->getOutput())
        ->toBe("Unavailable.\n");
    file_put_contents($this->statusDirectory.'/state.json', json_encode($this->statusState));
    expect(statusProcess($this->statusRoot, $this->statusToken, '/community/admin/system/upgrade/')->getOutput())
        ->toBe("NovFora is upgrading. Please retry shortly.\n");
    expect(statusProcess($this->statusRoot, $this->statusToken, '/community/admin/system/upgrade?x=1')->getOutput())
        ->toBe("NovFora is upgrading. Please retry shortly.\n");
});

it('requires the hidden same-capability form token before queueing rollback', function (): void {
    $before = file_get_contents($this->statusDirectory.'/state.json');
    expect(statusProcess($this->statusRoot, $this->statusToken, '/community/admin/system/upgrade', 'POST', 'wrong')->getOutput())
        ->toBe("Unavailable.\n")
        ->and(file_get_contents($this->statusDirectory.'/state.json'))->toBe($before);

    statusProcess($this->statusRoot, $this->statusToken, '/community/admin/system/upgrade', 'POST', $this->statusToken);
    $updated = json_decode(file_get_contents($this->statusDirectory.'/state.json'), true);
    expect($updated['phase'])->toBe('rollback_requested')
        ->and($updated['run_id'])->toBe($this->statusState['run_id'])
        ->and($updated['root'])->toBe('/private/never-display')
        ->and($updated['plan'])->toBe($this->statusState['plan']);
});

it('turns a snapshotting scheduler tick into a direct preparation command before Laravel boots', function (): void {
    $state = [...$this->statusState, 'phase' => 'snapshotting'];
    file_put_contents($this->statusDirectory.'/state.json', json_encode($state));
    $script = 'require '.var_export(base_path('bootstrap/release-upgrade.php'), true).';'
        .'$args=["artisan","schedule:run"];'
        .'$handled=novfora_release_preboot('.var_export($this->statusRoot, true).',true,$args);'
        .'echo json_encode(["handled"=>$handled,"argv"=>$_SERVER["argv"]]);';
    $process = new Process([PHP_BINARY, '-r', $script]);
    $process->run();
    $result = json_decode($process->getOutput(), true);
    expect($result['handled'])->toBeFalse()
        ->and($result['argv'])->toBe(['artisan', 'novfora:release-upgrade', '--advance']);
});
