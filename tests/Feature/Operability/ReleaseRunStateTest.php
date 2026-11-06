<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Upgrade\ReleaseRunState;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->releaseStateDirectory = sys_get_temp_dir().'/novfora-state-'.bin2hex(random_bytes(8));
    $this->releaseState = new ReleaseRunState($this->releaseStateDirectory);
});

afterEach(function () {
    File::deleteDirectory($this->releaseStateDirectory);
});

it('persists a complete run atomically outside the database', function () {
    $run = ['phase' => 'requested', 'version' => '1.4.0', 'actor_id' => 42];
    $this->releaseState->locked(fn () => $this->releaseState->write($run));
    expect((new ReleaseRunState($this->releaseStateDirectory))->read())->toBe($run)
        ->and($this->releaseState->isActive())->toBeTrue()
        ->and(glob($this->releaseStateDirectory.'/.state-*'))->toBe([]);
});

it('does not allow an overlapping writer even if its recorded run is old', function () {
    $this->releaseState->locked(function () {
        $this->releaseState->write(['phase' => 'applying', 'started_at' => 1]);
        expect(fn () => (new ReleaseRunState($this->releaseStateDirectory))->locked(fn () => null))
            ->toThrow(RuntimeException::class, 'Another upgrade operation');
    });
    expect($this->releaseState->locked(fn () => 'released'))->toBe('released');
});

it('fails closed on corrupt existing state rather than presenting an idle updater', function () {
    $this->releaseState->prepareDirectory();
    file_put_contents($this->releaseStateDirectory.'/state.json', '{broken');
    expect(fn () => $this->releaseState->isActive())->toThrow(JsonException::class);
});

it('releases its operating-system lock when the operation throws', function () {
    try {
        $this->releaseState->locked(fn () => throw new RuntimeException('failure'));
    } catch (RuntimeException) {
    }
    expect($this->releaseState->locked(fn () => true))->toBeTrue();
});
