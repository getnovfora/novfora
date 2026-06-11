<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\StructureService;
use App\Models\Group;
use App\Settings\Settings;
use App\Support\Audit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| The ACP v1 admin journey + screenshot gate. The journey (login → dashboard → create a board from the
| structure manager → see it on the public index) is the functional acceptance test for the panel; the
| screenshot run captures the dashboard, structure manager, a settings page, and the audit viewer in BOTH
| colour modes at mobile (360px) and desktop (1280px) for PR review (tests/Browser/screenshots/acp-*.png).
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = Users::withTwoFactor(Users::inGroups(['admins'], [
        'username' => 'adminjo', 'email' => 'adminjo@novfora.test', 'display_name' => 'Admin Jo',
    ]));
});

it('lets an admin create a board from the ACP and see it on the public index', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin)
            ->visit(route('admin.dashboard'))
            ->waitForText('System health', 12)
            ->assertSee('Dashboard')

            ->visit(route('admin.structure'))
            ->waitForText('New board', 12)
            ->press('New board')
            ->waitFor('@acp-board-name', 10)
            ->type('@acp-board-name', 'Dusk Lounge')
            ->pause(300)
            ->press('Create')
            ->waitForText('Created', 12)
            ->assertSee('Dusk Lounge')

            ->visit(route('forums.index'))
            ->waitForText('Dusk Lounge', 12)
            ->assertSee('Dusk Lounge');
    });
});

it('lets an admin create a coloured custom group from the ACP', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin)
            ->visit(route('admin.members.groups'))
            ->waitForText('Member groups', 12)
            ->assertSee('Moderators')   // a seeded system group is listed
            ->press('New group')
            ->waitFor('@acp-group-name', 10)
            ->type('@acp-group-name', 'Beta Crew')
            ->select('color', 'violet')
            ->pause(300)
            ->press('Create group')
            ->waitForText('Created group', 12)
            ->assertSee('Beta Crew');
    });
});

it('captures the ACP pages in light + dark at mobile + desktop', function () {
    // Populate the panel so the shots aren't empty: boards + a coloured post + a few audited settings writes.
    $structure = app(StructureService::class);
    $cat = $structure->create(['title' => 'Community', 'type' => 'category']);
    $lobby = $structure->create(['title' => 'Lobby', 'type' => 'forum', 'parent_id' => $cat->id]);
    $structure->create(['title' => 'Off-topic', 'type' => 'forum', 'parent_id' => $cat->id]);

    // ACP v2: colour seeded groups so names render in colour, and create a topic by the (coloured) admin so a
    // "coloured post" appears in the topic view.
    Group::where('slug', 'admins')->update(['color' => 'violet']);
    Group::where('slug', 'moderators')->update(['color' => 'red']);
    $topic = app(PostService::class)->createTopic(
        $this->admin->fresh(), $lobby, 'A post in colour', 'tiptap_json', Content::doc('Hello, in colour.'),
    );

    $settings = app(Settings::class);
    $settings->set('general.site_name', 'Campfire');
    $settings->set('moderation.new_user_hold_posts', 1);
    Audit::log('ban.created');
    Audit::log('topic.moved');

    $viewports = [['desktop', 1280, 1000], ['mobile', 360, 800]];
    $modes = ['light', 'dark'];
    $pages = [
        ['dashboard', fn () => route('admin.dashboard')],
        ['groups', fn () => route('admin.members.groups')],
        ['structure', fn () => route('admin.structure')],
        ['audit', fn () => route('admin.system.audit')],
        ['coloured-post', fn () => route('topics.show', $topic)],
    ];

    $this->browse(function (Browser $browser) use ($viewports, $modes, $pages) {
        $browser->loginAs($this->admin);

        foreach ($viewports as [$vp, $w, $h]) {
            foreach ($modes as $mode) {
                foreach ($pages as [$name, $url]) {
                    $browser->resize($w, $h)->visit($url())->pause(250);
                    $browser->script("document.documentElement.setAttribute('data-theme','{$mode}');document.documentElement.setAttribute('data-color-mode','{$mode}');");
                    $browser->pause(300)->screenshot("acp-{$name}-{$mode}-{$vp}");
                }
            }
        }
    });

    expect(glob(base_path('tests/Browser/screenshots/acp-*.png')))->not->toBeEmpty();
});
