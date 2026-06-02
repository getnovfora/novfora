<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Services\Tier\Capability;
use App\Services\Tier\ServiceTier;
use App\Services\Tier\Tier;

// The non-negotiable "service-tier forced-absence/fallback" suite (M0 slice, ADR-0003):
// detection must NEVER throw, and enhanced services that are configured-but-down must be reported
// as such while the baseline keeps working.

it('detects baseline and never throws on default config', function () {
    $snapshot = app(ServiceTier::class)->snapshot(fresh: true);

    expect($snapshot->overall)->toBe(Tier::Baseline)
        ->and($snapshot->capabilities[Capability::Cache->value]->tier)->toBe(Tier::Baseline)
        ->and($snapshot->capabilities[Capability::Queue->value]->tier)->toBe(Tier::Baseline);
});

it('reports a configured-but-unreachable enhanced service as down, without throwing', function () {
    config([
        'cache.default' => 'redis',
        'database.redis.default.host' => '10.255.255.1', // black-holed; the probe must not hang/throw
        'database.redis.default.port' => 6399,
    ]);

    $snapshot = app(ServiceTier::class)->snapshot(fresh: true);

    expect($snapshot->capabilities[Capability::Cache->value]->tier)->toBe(Tier::Enhanced)
        ->and($snapshot->services['redis']->configured)->toBeTrue()
        ->and($snapshot->services['redis']->reachable)->toBeFalse();
});

it('never throws when search points at a dead Meilisearch host', function () {
    config([
        'scout.driver' => 'meilisearch',
        'scout.meilisearch.host' => 'http://10.255.255.1:7700',
    ]);

    $snapshot = app(ServiceTier::class)->snapshot(fresh: true);

    expect($snapshot->services['meilisearch']->configured)->toBeTrue()
        ->and($snapshot->services['meilisearch']->reachable)->toBeFalse();
});

it('reports services as not-configured (reachable=null) on the baseline tier', function () {
    $snapshot = app(ServiceTier::class)->snapshot(fresh: true);

    expect($snapshot->services['redis']->configured)->toBeFalse()
        ->and($snapshot->services['redis']->reachable)->toBeNull();
});

it('hearth:tier command succeeds even with a dead enhanced service', function () {
    config([
        'cache.default' => 'redis',
        'database.redis.default.host' => '10.255.255.1',
    ]);

    $this->artisan('hearth:tier')->assertSuccessful();
});
