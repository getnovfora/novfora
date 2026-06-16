<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\FollowService;
use App\Forum\PostService;
use App\Models\Forum;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| P2-M5 "social pack" acceptance battery: follow/unfollow on a member profile, the following feed tab on the
| forum index, reaction-driven reputation (queued ShouldQueue listeners run inline under QUEUE_CONNECTION=sync
| so points are immediately visible), and post-count badge award. Screenshot gate captures the member profile
| (follow panel + badges + reputation) and the forum index (following feed tab active) in both colour modes at
| mobile (360 px) and desktop (1280 px), writing tests/Browser/screenshots/p2m5-*.png. Mirrors
| ActivityFeedJourneyTest (seed, loginAs, data-theme setter, glob sanity check).
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->forum = Forum::create(['slug' => 'p2m5-general', 'title' => 'General', 'type' => 'forum']);

    // viewer — TL1 so follow.create is ALLOW and they can react
    $this->viewer = Users::inGroups(['members', 'tl1'], [
        'username' => 'p2m5viewer', 'email' => 'viewer@novfora.test', 'display_name' => 'Viewer',
    ]);

    // author — a TL1 member the viewer will follow; they create the topic
    $this->author = Users::inGroups(['members', 'tl1'], [
        'username' => 'p2m5author', 'email' => 'author@novfora.test', 'display_name' => 'Author',
    ]);

    // Pre-seed the author's topic via PostService (the same approach ActivityFeedJourneyTest uses) so the
    // following feed has content before the browser steps run. QUEUE_CONNECTION=sync → AwardPostCountBadges
    // runs inline here; the opening post alone satisfies the first-post threshold.
    $this->topic = app(PostService::class)->createTopic(
        $this->author, $this->forum, 'Author social topic', 'tiptap_json', Content::doc('The author opening post.'),
    );

    // Refresh author to pick up any denorm changes the inline listeners wrote.
    $this->author->refresh();
    $this->op = $this->topic->posts()->orderBy('position')->orderBy('id')->first();
});

// ── Journey (a): FOLLOW ──────────────────────────────────────────────────────────────────────────────

it('viewer follows the author on their profile and the follower count rises to 1', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->viewer)
            ->visit(route('profiles.show', $this->author))
            ->waitFor('@follow-panel', 15)
            ->assertSeeIn('@follower-count', '0')

            // The follow button is visible for a TL1 viewer looking at another member's profile.
            ->assertPresent('@follow-button')
            ->click('@follow-button')

            // Livewire round-trip: follower count rises to 1 and the button switches to Unfollow.
            ->waitForText('Unfollow', 12)
            ->assertSeeIn('@follower-count', '1')
            ->assertSeeIn('@follow-button', 'Unfollow');
    });
});

// ── Journey (b): FOLLOWING FEED ──────────────────────────────────────────────────────────────────────

it('following feed tab shows the followed author\'s topic after viewer follows them', function () {
    // Wire the follow relationship in the DB directly so the browser journey starts from a known state.
    app(FollowService::class)->follow($this->viewer, $this->author);

    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->viewer)
            ->visit(route('forums.index'))
            ->waitFor('@activity-feed', 15)

            // Click the Following tab and wait for the Livewire swap.
            ->click('@feed-tab-following')
            ->waitFor('@activity-feed', 12)
            ->pause(400) // let wire:click round-trip settle

            // The author's topic must appear inside the feed.
            ->assertSeeIn('@activity-feed', 'Author social topic');
    });
});

// ── Journey (c): REPUTATION ──────────────────────────────────────────────────────────────────────────

it('viewer reacts helpful to the author\'s post and the author gains reputation', function () {
    $postId = $this->op->id;

    $this->browse(function (Browser $browser) use ($postId) {
        $browser->loginAs($this->viewer)
            ->visit(route('topics.show', $this->topic))
            ->waitFor('@reactions-'.$postId, 15)

            // Click the helpful reaction button.
            ->assertPresent('@react-'.$postId.'-helpful')
            ->click('@react-'.$postId.'-helpful')

            // Wait for the count badge to appear (Livewire re-render).
            ->waitFor('@react-count-'.$postId.'-helpful', 12);
    });

    // QUEUE_CONNECTION=sync → AwardReactionReputation ran inline at Reacted::dispatch time above.
    // The author's reputation_points denorm is already incremented. Refresh and assert.
    $this->author->refresh();

    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->viewer)
            ->visit(route('profiles.show', $this->author))
            ->waitFor('@reputation-points', 12)
            ->assertDontSeeIn('@reputation-points', '0'); // any non-zero value is correct
    });
});

// ── Journey (d): BADGE ───────────────────────────────────────────────────────────────────────────────

it('author has the first-post badge on their profile after publishing their first topic', function () {
    // QUEUE_CONNECTION=sync → AwardPostCountBadges fired inline when createTopic() dispatched TopicCreated
    // in beforeEach. Run the deterministic sweep to guarantee no missed event can hide the badge.
    Artisan::call('novfora:badges:recompute', ['--user' => $this->author->id]);

    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->viewer)
            ->visit(route('profiles.show', $this->author))
            ->waitFor('@profile-badges', 12)
            ->assertPresent('@profile-badge-first-post')
            ->assertSeeIn('@profile-badge-first-post', 'First Post');
    });
});

// ── Screenshot gate (profile + following feed, light/dark × mobile/desktop) ─────────────────────────

it('captures the member profile and following feed in light + dark at mobile + desktop', function () {
    // Set up: viewer follows author so the following feed tab has content.
    app(FollowService::class)->follow($this->viewer, $this->author);

    // Drain badge sweep so the profile shows the first-post badge in screenshots.
    Artisan::call('novfora:badges:recompute', ['--user' => $this->author->id]);

    $viewports = [['mobile', 360, 1080], ['desktop', 1280, 1000]];
    $modes = ['light', 'dark'];

    $this->browse(function (Browser $browser) use ($viewports, $modes) {
        $shoot = function (Browser $b, string $name, string $mode): void {
            $b->script("document.documentElement.setAttribute('data-theme','{$mode}');document.documentElement.setAttribute('data-color-mode','{$mode}');");
            $b->pause(300)->screenshot($name);
        };

        // ── author profile (follow panel + badges + reputation) ────────────────────────────────────
        $browser->loginAs($this->viewer);

        foreach ($viewports as [$vp, $w, $h]) {
            foreach ($modes as $mode) {
                $browser->resize($w, $h)
                    ->visit(route('profiles.show', $this->author))
                    ->waitFor('@follow-panel', 15)
                    ->waitFor('@profile-badges', 12)
                    ->pause(250);
                $shoot($browser, "p2m5-profile-{$mode}-{$vp}", $mode);
            }
        }

        // ── forum index with following feed tab active ─────────────────────────────────────────────
        foreach ($viewports as [$vp, $w, $h]) {
            foreach ($modes as $mode) {
                $browser->resize($w, $h)
                    ->visit(route('forums.index'))
                    ->waitFor('@activity-feed', 15)
                    ->click('@feed-tab-following')
                    ->waitFor('@activity-feed', 12)
                    ->pause(400);
                $shoot($browser, "p2m5-following-feed-{$mode}-{$vp}", $mode);
            }
        }
    });

    expect(glob(base_path('tests/Browser/screenshots/p2m5-*.png')))->not->toBeEmpty();
});
