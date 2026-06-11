<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Support\Users;

/*
| The default-theme screenshot gate (theme-design-brief §6 / kickoff PROCESS), refreshed for polish round 1:
| capture the core pages — forum index, the info-dense BOARD VIEW (topic table + sub-boards), a TOPIC with the
| left poster sidebar, auth, and settings — in BOTH colour modes at mobile (360px) and desktop (1280px). A
| capture run, not an assertion suite: it forces each mode by setting [data-theme] on <html> (what the toggle
| does) and writes tests/Browser/screenshots/theme-*.png for owner review on the PR.
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoSeeder::class); // a believable community for the forum index

    $this->shotUser = Users::inGroups(['members', 'tl2'], [
        'username' => 'maya', 'email' => 'maya@novfora.test', 'display_name' => 'Maya Rivera',
    ]);
    $mod = Users::inGroups(['moderators', 'tl4'], [
        'username' => 'morgan', 'email' => 'morgan@novfora.test', 'display_name' => 'Morgan Lee',
    ]);

    // A board that has BOTH a sub-board and a populated topic table, to showcase items 2 + 3 in one shot.
    $board = Forum::create(['slug' => 'showcase', 'title' => 'General Discussion', 'type' => 'forum',
        'description' => 'Introductions, ideas, and everything in between.']);
    Forum::create(['slug' => 'showcase-news', 'title' => 'Announcements', 'type' => 'forum',
        'parent_id' => $board->id, 'description' => 'Read-only updates from the team.']);

    $svc = app(PostService::class);
    $topics = [
        ['What features matter most in a forum?', 'Search, anti-spam, mobile UX — what do you value most?'],
        ['Share your community setup', 'Show us how you run your board.'],
        ['Weekly check-in — what are you building?', 'Tell the community what you shipped this week.'],
    ];
    $first = null;
    foreach ($topics as [$title, $body]) {
        $t = $svc->createTopic($this->shotUser, $board, $title, 'markdown', ['source' => $body]);
        $svc->reply($mod, $t, 'markdown', ['source' => 'Great question — here are my thoughts on '.$title]);
        $first ??= $t;
    }
    $sub = Forum::where('slug', 'showcase-news')->first();
    $svc->createTopic($mod, $sub, 'Welcome to the community', 'markdown', ['source' => 'Please read the rules.']);

    $this->shotBoard = $board;
    // A topic whose stream has a member starter AND a staff reply, so the left sidebar shows both a plain
    // poster and a "Moderator" badge.
    $this->shotTopic = $first;
});

it('captures the core pages in light + dark at mobile + desktop', function () {
    $viewports = [['desktop', 1280, 900], ['mobile', 360, 780]];
    $modes = ['light', 'dark'];

    $this->browse(function (Browser $browser) use ($viewports, $modes) {
        $shoot = function (Browser $b, string $name, string $mode) {
            // Force the colour mode the way the toggle does (set [data-theme] on <html>), then settle + shoot.
            $b->script("document.documentElement.setAttribute('data-theme','{$mode}');document.documentElement.setAttribute('data-color-mode','{$mode}');");
            $b->pause(300)->screenshot($name);
        };

        // Guest pages: forum index, the board view (table + sub-boards), a populated topic, the login page.
        foreach ($viewports as [$vp, $w, $h]) {
            foreach ($modes as $mode) {
                $browser->resize($w, $h)->visit(route('forums.index'))->pause(150);
                $shoot($browser, "theme-forum-index-{$mode}-{$vp}", $mode);

                $browser->resize($w, $h)->visit(route('forums.show', $this->shotBoard))->pause(150);
                $shoot($browser, "theme-board-{$mode}-{$vp}", $mode);

                $browser->resize($w, $h)->visit(route('topics.show', $this->shotTopic))->pause(150);
                $shoot($browser, "theme-topic-{$mode}-{$vp}", $mode);

                $browser->resize($w, $h)->visit(route('login'))->pause(150);
                $shoot($browser, "theme-auth-login-{$mode}-{$vp}", $mode);
            }
        }

        // Signed-in page: the appearance settings screen itself.
        $browser->loginAs($this->shotUser);
        foreach ($viewports as [$vp, $w, $h]) {
            foreach ($modes as $mode) {
                $browser->resize($w, $h)->visit(route('settings.appearance'))->pause(150);
                $shoot($browser, "theme-settings-appearance-{$mode}-{$vp}", $mode);
            }
        }
    });

    // Sanity: the capture run reached the end and wrote files.
    expect(glob(base_path('tests/Browser/screenshots/theme-*.png')))->not->toBeEmpty();
});
