<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Support\Users;

/*
| v1.3 Phase 3B (UX-1 / UX-3 / UX-4) in-browser guard — CI-only (no Chrome on the build env). The redesigned
| front-of-site must render and FIT the smallest supported widths with NO horizontal scroll: the card-based
| forum index, the board topic list, and — in the sm–md range where the full search bar is hidden and the
| hamburger is gone — the compact search entry stays reachable.
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->member = Users::inGroups(['members', 'tl1'], [
        'username' => 'mobileux', 'email' => 'mobileux@novfora.test', 'display_name' => 'MobileUX',
    ]);
    // A category + forum so the redesigned index renders a labelled category board card, and the board view
    // has a topic to list.
    $this->category = Forum::create(['slug' => 'main', 'title' => 'Main Category', 'type' => 'category']);
    $this->forum = Forum::create(['slug' => 'lounge', 'title' => 'The Lounge', 'type' => 'forum', 'parent_id' => $this->category->id]);
    app(PostService::class)->createTopic($this->member, $this->forum, 'A first topic', 'markdown', ['source' => 'Hello there.']);
});

it('renders the card-based forum index at 390px with no horizontal scroll (UX-1)', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->member)->visit(route('forums.index'))->resize(390, 844);

        expect((int) $browser->script('return document.documentElement.scrollWidth')[0])->toBeLessThanOrEqual(390);
        // The redesigned index groups forums into labelled category board cards.
        $browser->assertPresent('section[aria-labelledby^="cat-"]');
    });
});

it('renders the board topic list at 390px with no horizontal scroll (UX-4)', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->member)->visit(route('forums.show', $this->forum))->resize(390, 844);

        expect((int) $browser->script('return document.documentElement.scrollWidth')[0])->toBeLessThanOrEqual(390);
        $browser->assertSee('A first topic');
    });
});

it('keeps search reachable via the compact entry in the sm–md range (UX-3)', function () {
    $this->browse(function (Browser $browser) {
        // 700px is inside sm–md: the hamburger (with its search) is gone and the full search bar isn't shown
        // yet, so the compact search icon link must be visible so search is reachable at every width.
        $browser->loginAs($this->member)->visit(route('forums.index'))->resize(700, 900);

        $display = $browser->script("return getComputedStyle(document.querySelector('header a[aria-label=Search]')).display")[0];
        expect($display)->not->toBe('none');
    });
});
