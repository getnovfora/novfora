<?php

// SPDX-License-Identifier: Apache-2.0

use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('renders the audit log and filters by action prefix', function () {
    Audit::log('topic.created');
    Audit::log('ban.created');

    Livewire::actingAs(Users::withTwoFactor(Users::inGroups(['admins'])))
        ->test('admin.audit-log')
        ->assertSee('ban.created')
        ->assertSee('topic.created')
        ->set('action', 'ban.')
        ->assertSee('ban.created')
        ->assertDontSee('topic.created');
});

it('forbids the audit log to a non-admin (self-guard)', function () {
    Livewire::actingAs(Users::inGroups(['members']))
        ->test('admin.audit-log')
        ->assertForbidden();
});

it('lists the scheduled tasks for an admin', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])))
        ->get(route('admin.system.tasks'))
        ->assertOk()
        ->assertSee('Scheduled tasks')
        ->assertSee('Queue drain')
        ->assertSee('Automated backups');
});

it('renders the migrated system pages inside the admin shell nav', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])))
        ->get(route('admin.system.backups'))
        ->assertOk()
        ->assertSee('Backups')
        ->assertSee('Forums & structure'); // a nav item only the shell renders
});
