<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Group;
use App\Models\User;
use App\Upgrade\ReleaseRunState;
use App\Upgrade\UpgradeRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->releaseStateDirectory = sys_get_temp_dir().'/novfora-panel-release-'.bin2hex(random_bytes(8));
    app()->instance(ReleaseRunState::class, new ReleaseRunState($this->releaseStateDirectory));
    config(['app.version' => '1.3.0', 'releases.trusted_keys' => ['test' => base64_encode(str_repeat('x', 32))]]);
});

afterEach(function () {
    File::deleteDirectory($this->releaseStateDirectory);
});

function releasePanelOwner(): User
{
    $owner = Users::withTwoFactor(Users::inGroups(['admins']));
    $owner->groups()->updateExistingPivot((int) Group::where('slug', 'admins')->value('id'), ['is_co_owner' => true]);

    return $owner->fresh();
}

function fakeStableRelease(string $version): void
{
    $tag = 'v'.$version;
    $base = 'https://github.com/getnovfora/novfora/releases/download/'.$tag.'/';
    Http::fake(['api.github.com/repos/getnovfora/novfora/releases/latest' => Http::response([
        'draft' => false, 'prerelease' => false, 'tag_name' => $tag,
        'published_at' => '2026-11-06T00:00:00Z',
        'assets' => [
            ['name' => 'novfora-release.zip', 'browser_download_url' => $base.'novfora-release.zip'],
            ['name' => 'novfora-release.zip.sig', 'browser_download_url' => $base.'novfora-release.zip.sig'],
        ],
    ])]);
}

it('does not fetch a release on page load and offers a newer stable version after an owner checks', function () {
    $this->actingAs(releasePanelOwner());
    fakeStableRelease('1.4.0');

    $panel = Livewire::test('admin.upgrade')->assertSee('Installed version:')->assertDontSee('Newer stable release:');
    Http::assertNothingSent();

    $panel->call('checkRelease')
        ->assertSet('availableVersion', '1.4.0')
        ->assertSee('Newer stable release:')
        ->assertSee('Upgrade');
    Http::assertSentCount(1);
});

it('reports when no newer release with both assets is available', function () {
    $this->actingAs(releasePanelOwner());
    fakeStableRelease('1.3.0');

    Livewire::test('admin.upgrade')->call('checkRelease')
        ->assertSet('availableVersion', null)
        ->assertSee('No newer stable release with both ZIP and signature assets is available.');
});

it('queues an upgrade with a trusted server-side public directory', function () {
    $owner = releasePanelOwner();
    $this->actingAs($owner);
    config(['app.url' => 'https://forum.example/community']);
    fakeStableRelease('1.4.0');

    Livewire::test('admin.upgrade')->call('upgradeRelease')
        ->assertRedirect('https://forum.example/community/admin/system/upgrade');

    $run = app(ReleaseRunState::class)->read();
    expect($run['phase'])->toBe('requested')
        ->and($run['version'])->toBe('1.4.0')
        ->and($run['actor_id'])->toBe((int) $owner->id)
        ->and($run['public_dir'])->toBe(realpath(public_path()));
});

it('rejects a non-owner or revoked owner on Livewire actions', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);
    fakeStableRelease('1.4.0');
    Livewire::test('admin.upgrade')->call('checkRelease')->assertForbidden();
    Http::assertNothingSent();

    $owner = releasePanelOwner();
    $this->actingAs($owner);
    $panel = Livewire::test('admin.upgrade')->call('checkRelease');
    $statusPanel = Livewire::test('admin.upgrade');
    $owner->groups()->updateExistingPivot((int) Group::where('slug', 'admins')->value('id'), ['is_co_owner' => false]);
    $panel->call('upgradeRelease')->assertForbidden();
    expect(app(ReleaseRunState::class)->read())->toBe([]);

    $owner->forceFill(['two_factor_confirmed_at' => null])->save();
    $statusPanel->call('releaseStatus')->assertForbidden();
});

it('blocks the separate migration action during an active release and never displays private state errors', function () {
    $this->actingAs(releasePanelOwner());
    app(ReleaseRunState::class)->locked(fn () => app(ReleaseRunState::class)->write([
        'phase' => 'applying', 'version' => '1.4.0', 'error' => '/private/release/secret-path',
    ]));
    $this->mock(UpgradeRunner::class)->shouldNotReceive('runManual');

    Livewire::test('admin.upgrade')
        ->assertSee('Cron continues the upgrade')
        ->assertDontSee('/private/release/secret-path')
        ->call('releaseStatus')
        ->assertReturned(['phase' => 'applying', 'version' => '1.4.0'])
        ->call('apply')
        ->assertSee('release upgrade is already in progress');
});
