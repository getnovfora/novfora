<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Group;
use App\Upgrade\ReleaseCoordinator;
use App\Upgrade\ReleaseRunState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->releaseVersionDirectory = sys_get_temp_dir().'/novfora-release-version-'.bin2hex(random_bytes(8));
    app()->instance(ReleaseRunState::class, new ReleaseRunState($this->releaseVersionDirectory));
    config(['releases.trusted_keys' => ['test' => base64_encode(str_repeat('x', 32))]]);
    $this->originalAppVersion = [getenv('APP_VERSION'), $_ENV['APP_VERSION'] ?? null, $_SERVER['APP_VERSION'] ?? null];
    putenv('APP_VERSION');
    unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
    $parts = array_map('intval', explode('.', (string) config('app.version')));
    $parts[2]++;
    $version = implode('.', $parts);
    $base = 'https://github.com/getnovfora/novfora/releases/download/v'.$version.'/';
    Http::preventStrayRequests();
    Http::fake(['api.github.com/repos/getnovfora/novfora/releases/latest' => Http::response([
        'draft' => false, 'prerelease' => false, 'tag_name' => 'v'.$version,
        'published_at' => '2026-11-06T00:00:00Z',
        'assets' => [
            ['name' => 'novfora-release.zip', 'browser_download_url' => $base.'novfora-release.zip'],
            ['name' => 'novfora-release.zip.sig', 'browser_download_url' => $base.'novfora-release.zip.sig'],
        ],
    ])]);
    $this->releaseVersionOwner = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->releaseVersionOwner->groups()->updateExistingPivot(
        (int) Group::where('slug', 'admins')->value('id'), ['is_co_owner' => true],
    );
});

afterEach(function () {
    [$getenv, $env, $server] = $this->originalAppVersion;
    $getenv === false ? putenv('APP_VERSION') : putenv('APP_VERSION='.$getenv);
    if ($env === null) {
        unset($_ENV['APP_VERSION']);
    } else {
        $_ENV['APP_VERSION'] = $env;
    }
    if ($server === null) {
        unset($_SERVER['APP_VERSION']);
    } else {
        $_SERVER['APP_VERSION'] = $server;
    }
    File::deleteDirectory($this->releaseVersionDirectory);
});

it('rejects a pinned old APP_VERSION before creating release state', function () {
    putenv('APP_VERSION='.config('app.version'));

    expect(fn () => app(ReleaseCoordinator::class)->request($this->releaseVersionOwner))
        ->toThrow(RuntimeException::class, 'APP_VERSION is pinned');
    expect(app(ReleaseRunState::class)->read())->toBe([]);
});

it('allows a release request when no APP_VERSION override is present', function () {
    $run = app(ReleaseCoordinator::class)->request($this->releaseVersionOwner);

    expect($run['phase'])->toBe('requested')
        ->and(app(ReleaseRunState::class)->read()['phase'])->toBe('requested');
});
