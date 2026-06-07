<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Backup\RestoreState;
use App\Http\Controllers\HealthController;
use App\Upgrade\SchemaState;
use Illuminate\Support\Facades\Cache;

/** Point the file-based restore state at a throwaway path so a test never touches the real storage file. */
function isolateRestoreState(): void
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hearth-rh11-health-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);
    config([
        'hearth.backup.restore_state_path' => $dir.DIRECTORY_SEPARATOR.'hearth-restore.json',
        'hearth.backup.restore_lock_path' => $dir.DIRECTORY_SEPARATOR.'hearth-restore.lock',
    ]);
    app(RestoreState::class)->forget();
}

/*
| The /health endpoint (M5): a machine-readable status probe for uptime monitoring. Never throws, never
| leaks secrets, and signals DB-down via a 503 status code.
*/

it('reports a structured, healthy status with component checks', function () {
    $res = $this->getJson('/health');

    $res->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure([
            'status', 'app', 'version', 'installed', 'tier',
            'checks' => [
                'database' => ['ok'],
                'cache' => ['ok'],
                'queue' => ['ok', 'pending', 'last_drained_age_seconds'],
            ],
            'time',
        ]);

    expect($res->json('checks.database.ok'))->toBeTrue();
    expect($res->json('checks.cache.ok'))->toBeTrue();
});

it('flags a stale queue heartbeat as degraded', function () {
    Cache::put(HealthController::QUEUE_HEARTBEAT, now()->subHours(2)->timestamp, 3600);

    $res = $this->getJson('/health');

    $res->assertOk()->assertJsonPath('status', 'degraded');
    expect($res->json('checks.queue.ok'))->toBeFalse();
});

it('reports the no-SSH upgrade (schema) block (RH-10)', function () {
    $res = $this->getJson('/health');

    $res->assertOk()->assertJsonStructure([
        'schema' => ['pending', 'upgrading', 'stuck', 'auto', 'last'],
    ]);
    expect($res->json('schema.pending'))->toBeFalse();
    expect($res->json('schema.upgrading'))->toBeFalse();
    expect($res->json('schema.stuck'))->toBeFalse();
});

it('degrades when an auto-upgrade is stuck (operator-actionable)', function () {
    app(SchemaState::class)->put(['stuck' => true]);

    $res = $this->getJson('/health');

    $res->assertOk()->assertJsonPath('status', 'degraded');
    expect($res->json('schema.stuck'))->toBeTrue();
});

it('reports the no-SSH restore block (RH-11)', function () {
    isolateRestoreState();

    $res = $this->getJson('/health');

    $res->assertOk()->assertJsonStructure([
        'restore' => ['requested', 'running', 'stuck', 'last'],
    ]);
    expect($res->json('restore.requested'))->toBeFalse();
    expect($res->json('restore.running'))->toBeFalse();
    expect($res->json('restore.stuck'))->toBeFalse();
});

it('degrades when a restore is stuck (operator-actionable)', function () {
    isolateRestoreState();
    app(RestoreState::class)->put(['stuck' => true]);

    $res = $this->getJson('/health');

    $res->assertOk()->assertJsonPath('status', 'degraded');
    expect($res->json('restore.stuck'))->toBeTrue();

    app(RestoreState::class)->forget();
});

it('never exposes credentials in the payload', function () {
    $body = $this->getJson('/health')->getContent();

    expect($body)->not->toContain(config('database.connections.mysql.password') ?: '___no_password___');
    expect(strtolower($body))->not->toContain('secret');
});
