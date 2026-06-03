<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Support\Users;

/*
| The Spike-0 acceptance battery, re-run as a real in-browser journey against the live app (M2 DoD; first
| executed in M5). Requires a Chrome-enabled environment — see docker/dusk/. Run with: php artisan dusk.
|
| The server-observable half of this (canonical content surviving a Livewire round-trip, sanitized
| render, upload + mention authorization) is also covered by the always-on Feature suite, so CI without a
| browser still guards the integration; these Dusk journeys add the genuine in-browser proof.
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->member = Users::inGroups(['members'], ['username' => 'ada', 'email' => 'ada@hearth.test']);
    $this->forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
});

it('composes a topic in the WYSIWYG editor and posts it (end-to-end, in the real app)', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->member)
            ->visit(route('topics.create', $this->forum))
            ->waitFor('.hearth-prose', 15)                   // editor mounted (lazy TipTap chunk loaded)

            // Criterion #5 (a11y): the editor is an ARIA textbox, keyboard-reachable.
            ->assertAttribute('.hearth-prose', 'role', 'textbox')
            ->assertAttribute('.hearth-prose', 'aria-multiline', 'true')

            // Compose: a title, then real keystrokes into the WYSIWYG editor.
            ->type('@topic-title', 'My Dusk topic')
            ->click('.hearth-prose')
            ->keys('.hearth-prose', 'Composed in the real editor, posted for real.')
            ->pause(400)                                      // let the deferred $wire.set + blur sync settle

            // Post it. The page wire:navigates to the new topic, which renders the body from the SERVER-side
            // sanitized cache (the browser never supplies HTML — ADR-0005). A generous timeout covers the
            // cold first render of the topic page under headless Chrome.
            ->press('Post topic')
            ->waitForText('My Dusk topic', 20)
            ->assertSee('Composed in the real editor, posted for real.');
    });
});

it('survives a Livewire validation morph with zero content loss (criterion #1a, the GO-blocker)', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->member)
            ->visit(route('topics.create', $this->forum))
            ->waitFor('.hearth-prose', 15)

            // Type content into the editor, then force a Livewire re-render via a failed (empty-title) save.
            ->click('.hearth-prose')
            ->keys('.hearth-prose', 'Editor content that must survive the morph')
            ->pause(300)
            ->press('Post topic')
            ->waitForText('The title field is required.', 15) // the validation morph has rendered

            // The wire:ignore'd editor island + its synced canonical content survive the morph untouched.
            ->assertSeeIn('.hearth-prose', 'Editor content that must survive the morph');
    });
});
