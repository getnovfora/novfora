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
| P2-M4 moderation acceptance battery: merge two topics (redirect + folded posts), cross-page bulk-delete that
| silently skips a higher-ranked author's post, and a faceted search (forum + date). Screenshot gate captures
| the moderator topic toolbar + select mode in both colour modes at mobile (360 px) and desktop (1280 px),
| writing tests/Browser/screenshots/p2m4-*.png. Mirrors ActivityFeedJourneyTest (seed, loginAs, data-theme
| setter, glob sanity check).
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->forum = Forum::create(['slug' => 'p2m4-general', 'title' => 'General', 'type' => 'forum']);
    $this->mod = Users::inGroups(['moderators'], ['username' => 'p2m4mod', 'email' => 'mod@novfora.test', 'display_name' => 'ModUser']);
    $this->member = Users::inGroups(['members', 'tl1'], ['username' => 'p2m4member', 'email' => 'member@novfora.test', 'display_name' => 'Member']);
    $this->admin = Users::inGroups(['admins'], ['username' => 'p2m4admin', 'email' => 'admin@novfora.test', 'display_name' => 'Admin']);
});

it('merges one topic into another and redirects the source to the target', function () {
    $posts = app(PostService::class);
    $source = $posts->createTopic($this->member, $this->forum, 'Source thread', 'tiptap_json', Content::doc('source opening line'));
    $posts->reply($this->member, $source, 'tiptap_json', Content::doc('movealong reply text'));
    $target = $posts->createTopic($this->member, $this->forum, 'Target thread', 'tiptap_json', Content::doc('target opening line'));

    $this->browse(function (Browser $browser) use ($source, $target) {
        $browser->loginAs($this->mod)
            ->visit(route('topics.show', $source))
            ->waitForText('Source thread', 12)
            ->click('@topic-merge')
            ->waitFor('@merge-target', 8)
            ->select('@merge-target', (string) $target->id)
            ->click('@merge-confirm')
            // Lands on the target, which now carries the moved reply.
            ->waitForText('Target thread', 12)
            ->assertSee('movealong reply text');

        // The source URL now 301-redirects to the target (M3: the canonical path is /topics/{id}-{slug}).
        $browser->visit(route('topics.show', $source))
            ->waitForText('Target thread', 12)
            ->assertPathBeginsWith('/topics/'.$target->id);
    });
});

it('bulk-deletes an eligible post while silently skipping a higher-ranked author’s post', function () {
    $posts = app(PostService::class);
    $topic = $posts->createTopic($this->member, $this->forum, 'Mixed thread', 'tiptap_json', Content::doc('opening line here'));
    $memberReply = $posts->reply($this->member, $topic, 'tiptap_json', Content::doc('memberreply lowrank'));
    $adminReply = $posts->reply($this->admin, $topic, 'tiptap_json', Content::doc('adminreply highrank'));

    $this->browse(function (Browser $browser) use ($topic, $memberReply, $adminReply) {
        $browser->loginAs($this->mod)
            ->visit(route('topics.show', $topic))
            ->waitForText('Mixed thread', 12)
            ->click('@bulk-select-toggle')
            ->waitFor('@bulk-post-'.$adminReply->id, 15)
            // Check the LOWER post first (before the fixed action bar exists), then the higher one — so the
            // bar (which appears after the first selection) never overlaps the checkbox being clicked.
            ->click('@bulk-post-'.$adminReply->id)
            ->click('@bulk-post-'.$memberReply->id)
            ->waitFor('@bulk-delete', 15)
            ->pause(500) // let the bar settle before clicking (the local docker dusk env is slow)
            ->click('@bulk-delete')
            // The rank guard, proven end-to-end in the browser: the eligible (lower-ranked member) post is
            // deleted and disappears after the redirect; the higher-ranked admin post is skipped and survives.
            // A generous wait absorbs the slow local env (≈9 s page loads).
            ->waitUntilMissingText('memberreply lowrank', 25)
            ->assertSee('adminreply highrank');
    });
});

it('runs a faceted search scoped to a forum', function () {
    $posts = app(PostService::class);
    $other = Forum::create(['slug' => 'p2m4-other', 'title' => 'Other', 'type' => 'forum']);
    $posts->createTopic($this->member, $this->forum, 'Hit in general', 'tiptap_json', Content::doc('findme alpha keyword'));
    $posts->createTopic($this->member, $other, 'Hit in other', 'tiptap_json', Content::doc('findme beta keyword'));

    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->member)
            ->visit(route('search.index', ['q' => 'findme', 'forum' => $this->forum->id, 'from' => now()->subWeek()->toDateString()]))
            ->waitForText('result', 12)
            ->assertSee('Hit in general')
            ->assertDontSee('Hit in other');
    });
});

it('captures the moderator topic toolbar + select mode in light + dark at mobile + desktop', function () {
    $posts = app(PostService::class);
    $topic = $posts->createTopic($this->member, $this->forum, 'Screenshot thread', 'tiptap_json', Content::doc('a body to show'));
    $posts->reply($this->member, $topic, 'tiptap_json', Content::doc('a reply to select'));

    $viewports = [['mobile', 360, 1080], ['desktop', 1280, 1000]];
    $modes = ['light', 'dark'];

    $this->browse(function (Browser $browser) use ($topic, $viewports, $modes) {
        $shoot = function (Browser $b, string $name, string $mode): void {
            $b->script("document.documentElement.setAttribute('data-theme','{$mode}');document.documentElement.setAttribute('data-color-mode','{$mode}');");
            $b->pause(300)->screenshot($name);
        };

        $browser->loginAs($this->mod);

        foreach ($viewports as [$vp, $w, $h]) {
            foreach ($modes as $mode) {
                $browser->resize($w, $h)
                    ->visit(route('topics.show', $topic))
                    ->waitFor('@bulk-select-toggle', 12)
                    ->click('@bulk-select-toggle')->pause(250);
                $shoot($browser, "p2m4-moderation-{$mode}-{$vp}", $mode);
            }
        }
    });

    expect(glob(base_path('tests/Browser/screenshots/p2m4-*.png')))->not->toBeEmpty();
});
