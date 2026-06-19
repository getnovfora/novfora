<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| BUG-016: the user-settings chrome rendered its ten destinations through the shared flex-wrap <x-ui.tabs>
| bar, which spilled onto a second row at desktop width. The shell now renders a left sidebar nav (a single
| vertical column on desktop, a single horizontal scroll strip on mobile) — always one axis. Scoped to the
| settings shell so the shared tabs component is untouched. (The visual single-axis assertion lives in the
| Dusk journey; this guards the structure + that every destination stays reachable.)
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('renders the settings nav as a sidebar with all ten destinations reachable (BUG-016)', function () {
    $member = Users::inGroups(['members']);
    $html = $this->actingAs($member)->get(route('settings.profile'))->assertOk()->getContent();

    // The sidebar grid replaced the old flex-wrap tab bar.
    expect($html)->toContain('dusk="settings-nav"')
        ->and($html)->toContain('sm:grid-cols-[14rem_1fr]');

    $destinations = [
        'settings.profile', 'settings.primary-group', 'settings.appearance', 'settings.preferences',
        'settings.notifications', 'settings.ignore-list', 'settings.two-factor', 'settings.linked-accounts',
        'settings.api-tokens', 'settings.account',
    ];
    foreach ($destinations as $name) {
        expect($html)->toContain('href="'.route($name).'"');
    }
});
