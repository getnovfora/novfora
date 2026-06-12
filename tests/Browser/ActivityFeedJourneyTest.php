<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| P2-M3 activity-feed acceptance battery: the global, permission-filtered feed on the forum index shows
| recent community activity with an online dot for active members and reflects new replies. Screenshot gate
| captures the feed in both colour modes at mobile (360 px) and desktop (1280 px), writing
| tests/Browser/screenshots/p2m3-feed-*.png. Mirrors ContentDepthJourneyTest (seed, loginAs, data-theme
| setter, glob sanity check).
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->forum = Forum::create(['slug' => 'p2m3-general', 'title' => 'General', 'type' => 'forum']);
    $this->member = Users::inGroups(['members', 'tl1'], [
        'username' => 'feeduser', 'email' => 'feed@novfora.test', 'display_name' => 'FeedUser',
    ]);
    $this->poster = Users::inGroups(['members', 'tl1'], [
        'username' => 'replier', 'email' => 'replier@novfora.test', 'display_name' => 'Replier',
    ]);
    $this->topic = app(PostService::class)->createTopic(
        $this->member, $this->forum, 'A community topic', 'tiptap_json', Content::doc('The opening post.'),
    );
});

it('shows the feed on the forum index — recent topic + an online dot — and reflects a new reply', function () {
    $this->browse(function (Browser $browser) {
        // The member is "online" once they hit the index (ThrottledLastActive stamps last_active_at first).
        $browser->loginAs($this->member)
            ->visit(route('forums.index'))
            ->waitFor('@activity-feed', 15)
            ->assertSeeIn('@activity-feed', 'A community topic')
            ->assertSeeIn('@activity-feed', 'started a topic')
            ->assertPresent('@online-dot');

        // Opening the topic exercises the throttled view-count path (the increment itself is Pest-tested).
        $browser->visit(route('topics.show', $this->topic))
            ->waitForText('A community topic', 12);
    });

    // A new reply lands a post.created activity; the version bump rebuilds the feed → it must surface.
    app(PostService::class)->reply($this->poster, $this->topic, 'tiptap_json', Content::doc('A fresh reply in the feed'));

    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->member)
            ->visit(route('forums.index'))
            ->waitFor('@activity-feed', 15)
            ->assertSeeIn('@activity-feed', 'replied in');
    });
});

it('captures the activity feed on the forum index in light + dark at mobile + desktop', function () {
    $viewports = [['mobile', 360, 1080], ['desktop', 1280, 1000]];
    $modes = ['light', 'dark'];

    $this->browse(function (Browser $browser) use ($viewports, $modes) {
        $shoot = function (Browser $b, string $name, string $mode): void {
            $b->script("document.documentElement.setAttribute('data-theme','{$mode}');document.documentElement.setAttribute('data-color-mode','{$mode}');");
            $b->pause(300)->screenshot($name);
        };

        $browser->loginAs($this->member);

        foreach ($viewports as [$vp, $w, $h]) {
            foreach ($modes as $mode) {
                $browser->resize($w, $h)
                    ->visit(route('forums.index'))->waitFor('@activity-feed', 15)->pause(250);
                $shoot($browser, "p2m3-feed-{$mode}-{$vp}", $mode);
            }
        }
    });

    expect(glob(base_path('tests/Browser/screenshots/p2m3-feed-*.png')))->not->toBeEmpty();
});
