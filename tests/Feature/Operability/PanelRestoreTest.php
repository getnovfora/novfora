<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Backup\RestoreRunner;
use App\Backup\RestoreState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

/*
| Admin → System → Backups, the RH-11 restore action. Authorization is enforced both on the route group
| (admin.access + 2FA) AND inside the Livewire component (Livewire actions bypass route middleware), the
| destructive restore additionally self-guards on staff-2FA and requires a TYPED confirmation (the backup's
| exact name), and confirming only RECORDS the request (RestoreRunner::request) — the cron line performs it.
| The runner is mocked here so the panel's authz + typed-confirm contract is tested in isolation; the actual
| restore choreography is covered by RestoreRunnerTest.
*/

beforeEach(function () {
    $this->seed();

    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-rh11-panel-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);
    config([
        'novfora.backup.path' => $dir,
        'novfora.backup.restore_state_path' => $dir.DIRECTORY_SEPARATOR.'novfora-restore.json',
    ]);
    // A listable, resolvable archive (resolve() only checks the name pattern + is_file).
    $this->archive = 'novfora-20260601-120000.zip';
    file_put_contents($dir.DIRECTORY_SEPARATOR.$this->archive, 'x');

    app(RestoreState::class)->forget();
});

afterEach(fn () => app(RestoreState::class)->forget());

it('lets a 2FA admin view the Backups panel; blocks guests and non-admins', function () {
    $this->get(route('admin.system.backups'))->assertRedirect(); // guest → login

    $member = Users::inGroups(['members', 'tl4']);
    $this->actingAs($member)->get(route('admin.system.backups'))->assertForbidden();

    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin)->get(route('admin.system.backups'))->assertOk()->assertSee('Backups');
});

it('re-checks admin access inside the component, not only on the route', function () {
    $this->actingAs(Users::inGroups(['members', 'tl0']));

    Livewire::test('admin.backups')->assertStatus(403);
});

it('refuses the restore action for an admin without a confirmed second factor', function () {
    // admin.access is granted (mount passes), but the destructive restore self-guards on staff-2FA.
    $this->actingAs(Users::inGroups(['admins'])); // no 2FA confirmed

    Livewire::test('admin.backups')
        ->call('startRestore', $this->archive)
        ->assertStatus(403);
});

it('requires the typed name to match before a restore is requested', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    // The runner must NOT be asked to restore when the typed confirmation is wrong.
    $this->mock(RestoreRunner::class)->shouldReceive('request')->never();

    Livewire::test('admin.backups')
        ->call('startRestore', $this->archive)
        ->assertSet('confirming', $this->archive)
        ->set('typedName', 'not-the-name')
        ->call('confirmRestore')
        ->assertSet('messageVariant', 'danger')
        ->assertSee('does not match')
        ->assertNoRedirect();
});

it('records the restore request and sends the operator to the maintenance page on a matching confirmation', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    $this->mock(RestoreRunner::class)
        ->shouldReceive('request')
        ->once()
        ->with($this->archive, $admin->id, $admin->name);

    Livewire::test('admin.backups')
        ->call('startRestore', $this->archive)
        ->set('typedName', $this->archive)
        ->call('confirmRestore')
        ->assertRedirect('/'); // → the self-refreshing restore maintenance page
});

it('cancelling the confirmation clears the typed-confirm state without requesting a restore', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    $this->mock(RestoreRunner::class)->shouldReceive('request')->never();

    Livewire::test('admin.backups')
        ->call('startRestore', $this->archive)
        ->assertSet('confirming', $this->archive)
        ->call('cancelRestore')
        ->assertSet('confirming', null)
        ->assertSet('typedName', '');
});
