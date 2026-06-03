<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Support\Users;

/*
| The Spike-0 acceptance battery, re-run as a real in-browser journey against the live app (M2 DoD).
| Requires a Chrome-enabled environment (CI / a workstation); ChromeDriver is provisioned by
| `php artisan dusk:install`. Run with: php artisan dusk.
|
| The server-observable half of this (canonical content surviving a Livewire round-trip, sanitized
| render, upload + mention authorization) is also covered by the always-on Feature suite, so CI without
| a browser still guards the integration; this Dusk journey adds the genuine in-browser proof.
*/

uses(DatabaseTruncation::class);

it('composes a topic in the WYSIWYG editor, survives a Livewire morph, and posts it', function () {
    $this->seed(DatabaseSeeder::class);
    $member = Users::inGroups(['members'], ['username' => 'ada', 'email' => 'ada@hearth.test']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $this->browse(function (Browser $browser) use ($member, $forum) {
        $browser->loginAs($member)
            ->visit(route('topics.create', $forum))
            ->waitFor('.hearth-prose')                       // editor mounted (lazy TipTap chunk loaded)

            // Criterion #5 (a11y): the editor is an ARIA textbox.
            ->assertAttribute('.hearth-prose', 'role', 'textbox')
            ->assertAttribute('.hearth-prose', 'aria-multiline', 'true')

            // Type content, then force a Livewire re-render via a failed (empty-title) save.
            ->click('.hearth-prose')
            ->keys('.hearth-prose', 'Editor content that must survive the morph')
            ->pause(300)                                     // let the deferred $wire.set settle
            ->press('Post topic')
            ->pause(500)

            // Criterion #1a (GO-blocker): the wire:ignore'd editor + its synced content survive the morph.
            ->assertSeeIn('.hearth-prose', 'Editor content that must survive the morph')

            // Now complete the post.
            ->type('input[type=text]', 'My Dusk topic')
            ->press('Post topic')
            ->waitForText('My Dusk topic')
            ->assertSee('Editor content that must survive the morph'); // rendered from the sanitized cache
    });
});
