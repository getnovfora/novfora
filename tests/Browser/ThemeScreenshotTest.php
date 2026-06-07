<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Topic;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Support\Users;

/*
| The default-theme screenshot gate (theme-design-brief §6 / kickoff PROCESS): capture the four core pages in
| BOTH colour modes at mobile (360px) and desktop (1280px), via the real Dusk harness, for owner review on the
| PR. This is a capture run, not an assertion suite — it forces each mode by setting [data-theme] on <html>
| (exactly what the toggle does) and saves PNGs to tests/Browser/screenshots/theme-*.png.
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoSeeder::class); // a believable community (categories, forums, topics, replies)

    $this->shotUser = Users::inGroups(['members', 'tl2'], [
        'username' => 'maya', 'email' => 'maya@hearth.test', 'display_name' => 'Maya Rivera',
    ]);
    $this->shotTopic = Topic::query()->where('approved_state', 'approved')->orderByDesc('reply_count')->first()
        ?? Topic::query()->orderBy('id')->first();
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

        // Guest pages: forum index, a populated topic, the login page.
        foreach ($viewports as [$vp, $w, $h]) {
            foreach ($modes as $mode) {
                $browser->resize($w, $h)->visit(route('forums.index'))->pause(150);
                $shoot($browser, "theme-forum-index-{$mode}-{$vp}", $mode);

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
