<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Upgrade\SchemaState;
use Illuminate\Support\Facades\Cache;

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

it('never exposes credentials in the payload', function () {
    $body = $this->getJson('/health')->getContent();

    expect($body)->not->toContain(config('database.connections.mysql.password') ?: '___no_password___');
    expect(strtolower($body))->not->toContain('secret');
});
