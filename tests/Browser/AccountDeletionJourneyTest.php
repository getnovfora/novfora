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
| Account deletion (ADR-0025) acceptance battery: a real in-browser voluntary deletion from the settings
| Account tab — re-authenticate, confirm, delete — then assert the member is logged out, their profile 404s,
| and their authored post survives pseudonymised as "[Deleted]". Screenshot gate captures the delete surface
| (summary + armed confirm form) in both colour modes at mobile (360 px) and desktop (1280 px), writing
| tests/Browser/screenshots/p2-acct-delete-*.png for PR review. Mirrors PmJourneyTest (seed, loginAs,
| data-theme setter, glob sanity check).
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $this->member = Users::inGroups(['members', 'tl1'], [
        'username' => 'deluser', 'email' => 'deluser@novfora.test', 'display_name' => 'DelUser',
        'password' => 'correct-horse',
    ]);
    $this->viewer = Users::inGroups(['members', 'tl1'], [
        'username' => 'viewer', 'email' => 'viewer@novfora.test', 'display_name' => 'Viewer',
    ]);
    $this->topic = app(PostService::class)->createTopic(
        $this->member, $this->forum, 'A lasting topic', 'tiptap_json', Content::doc('This post body survives deletion.'),
    );
});

it('lets a member delete their own account from settings — logs out, 404s the profile, shows [Deleted]', function () {
    $oldId = (int) $this->member->id;

    $this->browse(function (Browser $browser) use ($oldId) {
        $browser->loginAs($this->member)
            ->visit(route('settings.account'))
            ->waitForText('Delete account', 15)
            ->click('@delete-initiate')
            ->waitFor('@delete-password', 10)
            ->type('@delete-password', 'correct-horse')
            ->check('@delete-confirm')
            ->click('@delete-confirm-submit')
            // Deletion redirects home ('/' 301s on to the forum index), so assert we navigated AWAY from the
            // settings page rather than pinning the exact post-redirect path.
            ->waitUntilMissing('@delete-confirm-submit', 25)
            ->assertPathIsNot('/settings/account');

        // Logged out: the auth-only settings route now bounces us away.
        $browser->visit(route('settings.account'))
            ->assertPathIsNot('/settings/account');

        // The profile 404s now that the row is gone.
        $browser->visit('/users/'.$oldId)
            ->waitForText('Page not found', 15)
            ->assertDontSee('deluser');
    });

    // The authored post survives, pseudonymised, and renders [Deleted] to another member.
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->viewer)
            ->visit(route('topics.show', $this->topic))
            ->waitForText('This post body survives deletion.', 15)
            ->assertSee('[Deleted]')
            ->assertDontSee('DelUser');
    });
});

it('captures the account-deletion settings surface in light + dark at mobile + desktop', function () {
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
                    ->visit(route('settings.account'))->waitForText('Delete account', 15)->pause(200);
                $shoot($browser, "p2-acct-delete-{$mode}-{$vp}", $mode);

                $browser->click('@delete-initiate')->waitFor('@delete-password', 10)->pause(200);
                $shoot($browser, "p2-acct-delete-armed-{$mode}-{$vp}", $mode);
            }
        }
    });

    expect(glob(base_path('tests/Browser/screenshots/p2-acct-delete-*.png')))->not->toBeEmpty();
});
