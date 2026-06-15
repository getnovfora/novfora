<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('renders the push column and the device-enable control on the notifications page', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'prefs@push.test']);

    $this->actingAs($user)->get(route('settings.notifications'))
        ->assertOk()
        ->assertSee('Push notifications on this device')
        ->assertSee('Enable on this device')
        ->assertSee('In-app')
        ->assertSee('Email')
        ->assertSee('Push');
});

it('persists a per-event push preference opt-out', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'prefs2@push.test']);

    Livewire::actingAs($user)
        ->test('settings.notification-preferences')
        ->set('prefs.reply_push', false)
        ->call('save')
        ->assertHasNoErrors();

    $pref = NotificationPreference::where('user_id', $user->id)->where('event_type', 'reply')->where('channel', 'push')->first();
    expect($pref)->not->toBeNull();
    expect((bool) $pref->enabled)->toBeFalse();
});

it('keeps per-event push on by default', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'prefs3@push.test']);

    Livewire::actingAs($user)
        ->test('settings.notification-preferences')
        ->call('save')
        ->assertHasNoErrors();

    $pref = NotificationPreference::where('user_id', $user->id)->where('event_type', 'mention')->where('channel', 'push')->first();
    expect((bool) $pref->enabled)->toBeTrue();
});
