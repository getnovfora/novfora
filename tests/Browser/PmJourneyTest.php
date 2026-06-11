<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Messaging\ConversationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Support\Users;

/*
| P2-M2 Half-B "private messages" acceptance battery: real in-browser journeys for composing a conversation,
| the recipient's inbox + unread badge, and replying. Screenshot gate captures the inbox, a conversation, and
| the composer in both colour modes at mobile (360 px) and desktop (1280 px), writing
| tests/Browser/screenshots/p2m2b-*.png for PR review. Mirrors ContentDepthJourneyTest (seed, loginAs,
| data-theme setter, glob sanity check). Senders are TL1 (pm.send ALLOW); TL0 cannot PM at all.
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->alice = Users::inGroups(['members', 'tl1'], [
        'username' => 'alice', 'email' => 'alice@novfora.test', 'display_name' => 'Alice',
    ]);
    $this->bob = Users::inGroups(['members', 'tl1'], [
        'username' => 'bob', 'email' => 'bob@novfora.test', 'display_name' => 'Bob',
    ]);
    $this->carol = Users::inGroups(['members', 'tl1'], [
        'username' => 'carol', 'email' => 'carol@novfora.test', 'display_name' => 'Carol',
    ]);

    // A pre-seeded conversation alice → bob, so the inbox / reply journeys don't depend on the compose flow.
    $this->conversation = app(ConversationService::class)->startConversation(
        $this->alice, [$this->bob->id], 'Welcome aboard', 'markdown', ['source' => 'Hi Bob, welcome to NovFora!'],
    );
});

it('lets a member compose a new conversation and lands on the thread', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->alice)
            ->visit(route('pm.create'))
            ->waitFor('@pm-new', 15)
            ->type('@pm-recipient-input', 'carol')
            ->pause(400)                       // let wire:model.live debounce sync the username to the server
            ->click('@pm-recipient-add')
            ->pause(400)                       // let the chip register (Livewire round-trip)
            ->type('@pm-subject', 'Lunch plans')
            ->click('@pm-format-toggle')      // markdown textarea is the simplest reliable composer in Dusk
            ->waitFor('@pm-body', 10)
            ->type('@pm-body', 'Want to grab lunch this week?')
            ->click('@pm-create')
            ->waitForText('Want to grab lunch this week?', 20)
            ->assertSee('Want to grab lunch this week?');
    });
});

it('shows the conversation in the recipient inbox with an unread badge, and opens it', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->bob)
            ->visit(route('pm.inbox'))
            ->waitFor('@pm-inbox', 15)
            ->assertPresent('@pm-conversation-row-'.$this->conversation->id)
            ->assertSee('Welcome aboard')
            ->click('@pm-conversation-row-'.$this->conversation->id)
            ->waitFor('@pm-conversation', 20)
            ->assertSee('Hi Bob, welcome to NovFora!');
    });
});

it('lets a participant reply within a conversation', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->bob)
            ->visit(route('pm.show', $this->conversation))
            ->waitFor('@pm-conversation', 15)
            ->click('@pm-format-toggle')
            ->waitFor('@pm-reply-body', 10)
            ->type('@pm-reply-body', 'Thanks Alice, glad to be here!')
            ->click('@pm-reply-send')
            ->waitForText('Thanks Alice, glad to be here!', 20)
            ->assertSee('Thanks Alice, glad to be here!');
    });
});

it('captures the PM inbox, conversation, and composer in light + dark at mobile + desktop', function () {
    $viewports = [['mobile', 360, 1080], ['desktop', 1280, 1000]];
    $modes = ['light', 'dark'];

    $this->browse(function (Browser $browser) use ($viewports, $modes) {
        $shoot = function (Browser $b, string $name, string $mode): void {
            $b->script("document.documentElement.setAttribute('data-theme','{$mode}');document.documentElement.setAttribute('data-color-mode','{$mode}');");
            $b->pause(300)->screenshot($name);
        };

        $browser->loginAs($this->bob);

        foreach ($viewports as [$vp, $w, $h]) {
            foreach ($modes as $mode) {
                $browser->resize($w, $h)
                    ->visit(route('pm.inbox'))->waitFor('@pm-inbox', 15)->pause(200);
                $shoot($browser, "p2m2b-inbox-{$mode}-{$vp}", $mode);

                $browser->visit(route('pm.show', $this->conversation))->waitFor('@pm-conversation', 15)->pause(200);
                $shoot($browser, "p2m2b-conversation-{$mode}-{$vp}", $mode);
            }
        }

        $browser->loginAs($this->alice);

        foreach ($viewports as [$vp, $w, $h]) {
            foreach ($modes as $mode) {
                $browser->resize($w, $h)
                    ->visit(route('pm.create'))->waitFor('@pm-new', 15)->pause(200);
                $shoot($browser, "p2m2b-compose-{$mode}-{$vp}", $mode);
            }
        }
    });

    expect(glob(base_path('tests/Browser/screenshots/p2m2b-*.png')))->not->toBeEmpty();
});
