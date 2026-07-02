<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Settings\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Support\Users;

/*
| BETA-2 / NOV-86 in-browser guard (CI-only — no Chrome on the build VPS): at 390×844 portrait the header
| must never exceed the viewport for ANY auth state or wordmark length — the brand link (min-w-0) is the
| one child that yields; the bell/PM/avatar cluster stays fully on-screen. At 768px the primary nav items
| must stay on one text line (admin-editable titles, NavigationManager).
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->member = Users::inGroups(['members', 'tl1'], [
        'username' => 'headeruser', 'email' => 'headeruser@novfora.test', 'display_name' => 'HeaderUser',
    ]);
});

it('never spills the signed-in header at 390px portrait, even with a long wordmark (BETA-2)', function () {
    // A deliberately long wordmark — the historical trigger for the spillover regression.
    app(Settings::class)->set('appearance.wordmark', 'NovFora Development Build Community');

    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->member)
            ->visit(route('forums.index'))
            ->resize(390, 844);

        // The whole document fits the viewport: no horizontal scroll.
        expect((int) $browser->script('return document.documentElement.scrollWidth')[0])
            ->toBeLessThanOrEqual(390);

        // The avatar dropdown trigger (right cluster) is fully on-screen.
        $right = (int) $browser->script("return Math.ceil(document.querySelector('header [aria-haspopup], header details summary, header .ml-auto').getBoundingClientRect().right)")[0];
        expect($right)->toBeLessThanOrEqual(390);

        // Mobile ownership: hamburger visible, primary nav + desktop search hidden.
        $browser->assertVisible('header button[aria-controls="mobile-nav"]');
        expect($browser->script("return getComputedStyle(document.querySelector('nav[aria-label=Primary]')).display")[0])->toBe('none');
    });
});

it('never spills the guest header at 360px (smallest supported width)', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit(route('forums.index'))
            ->resize(360, 800);

        expect((int) $browser->script('return document.documentElement.scrollWidth')[0])
            ->toBeLessThanOrEqual(360);
    });
});

it('keeps primary nav items on a single text line at 768px', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->member)
            ->visit(route('forums.index'))
            ->resize(768, 1024);

        $heights = $browser->script(
            "return Array.from(document.querySelectorAll('nav[aria-label=Primary] > a, nav[aria-label=Primary] button')).map(el => el.getBoundingClientRect().height)"
        )[0];

        expect($heights)->not->toBeEmpty();
        foreach ($heights as $h) {
            expect((float) $h)->toBeLessThan(48.0); // one line inside the h-14 bar
        }
    });
});
