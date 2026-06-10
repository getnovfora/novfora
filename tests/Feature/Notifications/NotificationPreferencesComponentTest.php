<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\DigestPreference;
use App\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| P2-M2 Half-A — the ⚡notification-preferences SFC. Per-event×channel toggles + the digest cadence picker
| over DigestPreference. Own-prefs-ONLY (auth re-asserted in mount + save); the dotted `pm.received` event
| binds safely through a dotless composite key.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->user = Users::inGroups(['members'], ['username' => 'prefuser', 'email' => 'pref@user.test']);
});

it('renders the preferences page with the cadence picker', function () {
    $this->actingAs($this->user)
        ->get(route('settings.notifications'))
        ->assertOk()
        ->assertSee('Email digest');
});

it('loads the user\'s existing preferences and cadence', function () {
    NotificationPreference::create(['user_id' => $this->user->getKey(), 'event_type' => 'reply', 'channel' => 'mail', 'enabled' => false]);
    DigestPreference::create(['user_id' => $this->user->getKey(), 'cadence' => 'weekly']);

    Livewire::actingAs($this->user)
        ->test('settings.notification-preferences')
        ->assertSet('prefs.reply_mail', false)
        ->assertSet('prefs.reply_database', true) // absent row defaults on
        ->assertSet('cadence', 'weekly');
});

it('saves per-event×channel toggles and the digest cadence (own prefs only)', function () {
    Livewire::actingAs($this->user)
        ->test('settings.notification-preferences')
        ->set('prefs.reply_mail', false)
        ->set('prefs.pm_received_mail', false) // dotted event name round-trips through the composite key
        ->set('cadence', 'daily')
        ->call('save')
        ->assertHasNoErrors();

    expect((bool) NotificationPreference::where('user_id', $this->user->getKey())->where('event_type', 'reply')->where('channel', 'mail')->value('enabled'))->toBeFalse()
        ->and((bool) NotificationPreference::where('user_id', $this->user->getKey())->where('event_type', 'pm.received')->where('channel', 'mail')->value('enabled'))->toBeFalse()
        ->and((bool) NotificationPreference::where('user_id', $this->user->getKey())->where('event_type', 'reaction')->where('channel', 'database')->value('enabled'))->toBeTrue()
        ->and(DigestPreference::where('user_id', $this->user->getKey())->value('cadence'))->toBe('daily');
});

it('rejects an unauthenticated visitor (own-prefs-only guard in mount)', function () {
    Livewire::test('settings.notification-preferences')->assertForbidden();
});

it('coerces an invalid cadence to immediate', function () {
    Livewire::actingAs($this->user)
        ->test('settings.notification-preferences')
        ->set('cadence', 'bogus')
        ->call('save')
        ->assertSet('cadence', 'immediate');

    expect(DigestPreference::where('user_id', $this->user->getKey())->value('cadence'))->toBe('immediate');
});
