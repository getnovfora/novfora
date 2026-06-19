<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Support\Users;

/*
| BUG-016 in-browser guard: at desktop width the ten settings destinations render as a single VERTICAL
| sidebar column — they used to wrap onto a second row through the shared flex-wrap tab bar. We assert every
| destination is visible/reachable and that all nav links share one left edge (a single column, not wrapped
| rows). Mirrors the suite idiom (DatabaseTruncation, seed, loginAs, resize).
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->member = Users::inGroups(['members', 'tl1'], [
        'username' => 'navuser', 'email' => 'navuser@novfora.test', 'display_name' => 'NavUser',
    ]);
});

it('renders the settings nav as a single-axis sidebar at desktop width (BUG-016)', function () {
    $labels = ['Profile', 'Groups', 'Appearance', 'Preferences', 'Notifications', 'Ignored', 'Security', 'Linked accounts', 'API tokens', 'Account'];

    $this->browse(function (Browser $browser) use ($labels) {
        $browser->loginAs($this->member)
            ->visit(route('settings.profile'))
            ->resize(1280, 900)
            ->assertVisible('@settings-nav');

        // Every destination is reachable from the nav.
        foreach ($labels as $label) {
            $browser->assertSeeIn('@settings-nav', $label);
        }

        // Single vertical column: every nav link shares the same left edge (no second row / no wrapping).
        $lefts = $browser->script("return Array.from(document.querySelectorAll('[dusk=settings-nav] a')).map(a => Math.round(a.getBoundingClientRect().left));")[0];
        expect(count(array_unique($lefts)))->toBe(1);
    });
});
