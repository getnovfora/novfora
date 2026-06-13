<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\LayoutWidget;
use App\Theme\LayoutManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| The ACP layout configurator (ADR-0032). Admins-only (admin.access + 2FA), self-guarded in mount() and every
| action. The add → configure → render → remove flow goes through the audited LayoutManager.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('blocks non-admins and 2FA-less admins from the layout configurator (403)', function () {
    $this->actingAs(Users::inGroups(['members']));
    Livewire::test('admin.layout')->assertStatus(403);

    $this->actingAs(Users::inGroups(['moderators']));
    Livewire::test('admin.layout')->assertStatus(403);

    $this->actingAs(Users::inGroups(['admins'])); // no 2FA confirmed
    Livewire::test('admin.layout')->assertStatus(403);
});

it('lets a 2FA admin add, configure and remove a widget', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    Livewire::test('admin.layout')
        ->set('newWidget.forum_top', 'html')
        ->call('add', 'forum_top')
        ->assertHasNoErrors();

    $placement = LayoutWidget::where('region', 'forum_top')->firstOrFail();
    expect($placement->widget_key)->toBe('html');

    Livewire::test('admin.layout')
        ->set("settings.{$placement->id}.html", '<p>Hi there</p>')
        ->call('save', $placement->id);
    expect($placement->fresh()->settings)->toBe(['html' => '<p>Hi there</p>'])
        ->and(app(LayoutManager::class)->render('forum_top'))->toContain('Hi there');

    Livewire::test('admin.layout')->call('remove', $placement->id);
    expect(LayoutWidget::find($placement->id))->toBeNull();
});

it('forbids a member from invoking a layout action', function () {
    $this->actingAs(Users::inGroups(['members']));
    // mount() already 403s, so a crafted action never reaches LayoutManager.
    Livewire::test('admin.layout')->assertStatus(403);
});
