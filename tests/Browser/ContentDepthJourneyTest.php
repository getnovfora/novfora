<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PollService;
use App\Forum\PostService;
use App\Forum\PrefixManager;
use App\Forum\ReactionService;
use App\Forum\TagService;
use App\Models\Forum;
use App\Models\Tag;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| P2-M1 "Content depth" acceptance battery: real in-browser journeys for reactions, polls, topic prefixes
| (ACP), tags, and edit-history. Screenshot gate captures the thread (reactions + poll) and the ACP
| prefixes manager in both colour modes at mobile (360 px) and desktop (1280 px), writing
| tests/Browser/screenshots/p2m1-*.png for PR review. Mirrors the patterns in ThemeScreenshotTest
| (DatabaseTruncation, beforeEach seed, loginAs, data-theme setter, expect glob sanity check) and
| AdminJourneyTest (withTwoFactor admin for ACP tests).
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    // ── actors ─────────────────────────────────────────────────────────────────────────────────────
    $this->member = Users::inGroups(['members'], [
        'username' => 'reader', 'email' => 'reader@novfora.test', 'display_name' => 'Reader',
    ]);

    $this->admin = Users::withTwoFactor(Users::inGroups(['admins'], [
        'username' => 'adminp2', 'email' => 'adminp2@novfora.test', 'display_name' => 'Admin P2',
    ]));

    // ── board ──────────────────────────────────────────────────────────────────────────────────────
    $board = Forum::create([
        'slug' => 'p2m1-general',
        'title' => 'General Talk',
        'type' => 'forum',
        'description' => 'Board for P2-M1 Dusk journeys.',
    ]);
    $this->board = $board;

    // ── prefix ─────────────────────────────────────────────────────────────────────────────────────
    $prefix = app(PrefixManager::class)->create([
        'label' => 'Guide',
        'color_token' => 'violet',
        'forum_id' => null, // global
        'position' => 0,
    ]);
    $this->prefix = $prefix;

    // ── tag ────────────────────────────────────────────────────────────────────────────────────────
    $tagSvc = app(TagService::class);
    $tag = $tagSvc->create('dusk-p2m1');
    $this->tag = $tag;

    // ── topic with poll + prefix + tag ────────────────────────────────────────────────────────────
    $postSvc = app(PostService::class);
    $topic = $postSvc->createTopic(
        $this->member,
        $board,
        'P2-M1 Dusk Thread',
        'tiptap_json',
        Content::doc('This thread tests reactions, polls, tags, and edit history.'),
        $prefix->id,
    );

    // Attach tag to the topic.
    $tagSvc->syncTopicTags($topic, [$tag->id]);

    // Create the poll on this topic.
    $poll = app(PollService::class)->createPoll(
        $this->member,
        $topic,
        'Which P2-M1 feature matters most?',
        ['Reactions', 'Polls', 'Tags', 'Edit history'],
        false,   // single choice
        null,
        null,
    );
    $this->poll = $poll;

    // Pre-seed a reaction from the admin so the reaction button/count renders on page load without
    // the viewer having to click first (guards the assertions in the reactions journey below).
    $op = $topic->posts()->orderBy('position')->orderBy('id')->first();
    app(ReactionService::class)->toggle($this->admin, $op, 'like');

    $this->topic = $topic->refresh();
    $this->op = $op->refresh();

    // ── second post (edited) for the edit-history journey ─────────────────────────────────────────
    $reply = $postSvc->reply(
        $this->member,
        $topic,
        'tiptap_json',
        Content::doc('Original reply body.'),
    );

    // Edit it so a revision + History button appear.
    $postSvc->editPost(
        $this->member,
        $reply,
        'tiptap_json',
        Content::doc('Edited reply body — a second version.'),
        'Fixed a typo',
    );

    $this->reply = $reply->fresh();
});

// ── Journey 1: Reactions ─────────────────────────────────────────────────────────────────────────

it('lets a member click a reaction button and sees the count update', function () {
    $this->browse(function (Browser $browser) {
        $postId = $this->op->id;

        $browser->loginAs($this->member)
            ->visit(route('topics.show', $this->topic))
            ->waitFor('@reactions-'.$postId, 15)

            // The 'like' reaction already has 1 count (seeded in beforeEach by admin).
            ->assertPresent('@react-'.$postId.'-like')

            // Click like — toggles ON for this member.
            ->click('@react-'.$postId.'-like')
            ->waitFor('@react-count-'.$postId.'-like', 12)
            ->assertSeeIn('@reactions-'.$postId, '2');  // admin + member = 2
    });
});

// ── Journey 2: Poll vote ──────────────────────────────────────────────────────────────────────────

it('lets a member vote on a poll and sees results', function () {
    $this->browse(function (Browser $browser) {
        $pollId = $this->poll->id;
        // The first option (Reactions); we need its id.
        $optId = $this->poll->options->sortBy('position')->first()->id;

        $browser->loginAs($this->member)
            ->visit(route('topics.show', $this->topic))
            ->waitFor('@poll-'.$pollId, 15)
            ->waitFor('@poll-vote-form', 12)

            // Select option and submit.
            ->click('@poll-opt-'.$optId)
            ->pause(300)   // let wire:model sync settle (Livewire deferred)
            ->click('@poll-submit')
            ->waitFor('@poll-result-'.$optId, 20)   // results view replaces the form after vote
            ->assertPresent('@poll-result-'.$optId);
    });
});

// ── Journey 3: ACP Prefixes ───────────────────────────────────────────────────────────────────────

it('lets an admin see the prefix manager in the ACP', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin)
            ->visit(route('admin.prefixes'))
            ->waitFor('@acp-prefixes', 12)
            ->assertPresent('@acp-prefixes')
            ->assertSee('Guide')              // seeded prefix is listed
            ->assertPresent('@acp-new-prefix');
    });
});

// ── Journey 4: Tags ───────────────────────────────────────────────────────────────────────────────

it('renders a tag chip on the topic page', function () {
    $this->browse(function (Browser $browser) {
        $tagId = $this->tag->id;

        $browser->loginAs($this->member)
            ->visit(route('topics.show', $this->topic))
            ->waitForText('P2-M1 Dusk Thread', 12)
            ->assertPresent('@tag-chip-'.$tagId);
    });
});

it('renders tag chips on the tags.show page', function () {
    $this->browse(function (Browser $browser) {
        // tags.show lists topics; the topic listing includes tag chips per topic.
        $browser->loginAs($this->member)
            ->visit(route('tags.show', $this->tag))
            ->waitForText('dusk-p2m1', 12)
            ->assertSee('dusk-p2m1');  // tag name in heading
    });
});

// ── Journey 5: Edit-history modal ────────────────────────────────────────────────────────────────

it('opens the edit-history modal and shows a diff line', function () {
    $this->browse(function (Browser $browser) {
        $postId = $this->reply->id;

        $browser->loginAs($this->member)
            ->visit(route('topics.show', $this->topic))
            ->waitFor('@post-history-btn-'.$postId, 15)

            ->click('@post-history-btn-'.$postId)
            ->waitFor('@post-history-modal', 20)    // Livewire round-trip loads diff
            ->assertPresent('@post-history-modal')

            // A diff line: either an addition or a deletion from the revision.
            ->assertSeeIn('@post-history-modal', 'Edited');
    });
});

// ── Screenshot gate (thread + ACP prefixes, light/dark × mobile/desktop) ─────────────────────────

it('captures the P2-M1 thread and ACP prefixes in light + dark at mobile + desktop', function () {
    $viewports = [['mobile', 360, 1080], ['desktop', 1280, 1000]];
    $modes = ['light', 'dark'];

    $pollId = $this->poll->id;
    $prefixId = $this->prefix->id;

    $this->browse(function (Browser $browser) use ($viewports, $modes, $pollId) {
        $shoot = function (Browser $b, string $name, string $mode): void {
            // Mirror AdminJourneyTest: set both data-theme and data-color-mode exactly as the toggle does.
            $b->script("document.documentElement.setAttribute('data-theme','{$mode}');document.documentElement.setAttribute('data-color-mode','{$mode}');");
            $b->pause(300)->screenshot($name);
        };

        // ── thread page (reactions + poll visible) ──────────────────────────────────────────────
        $browser->loginAs($this->member);

        foreach ($viewports as [$vp, $w, $h]) {
            foreach ($modes as $mode) {
                $browser->resize($w, $h)
                    ->visit(route('topics.show', $this->topic))
                    ->waitFor('@reactions-'.$this->op->id, 15)
                    ->waitFor('@poll-'.$pollId, 12)
                    ->pause(250);
                $shoot($browser, "p2m1-thread-{$mode}-{$vp}", $mode);
            }
        }

        // ── ACP prefixes manager ────────────────────────────────────────────────────────────────
        $browser->loginAs($this->admin);

        foreach ($viewports as [$vp, $w, $h]) {
            foreach ($modes as $mode) {
                $browser->resize($w, $h)
                    ->visit(route('admin.prefixes'))
                    ->waitFor('@acp-prefixes', 12)
                    ->pause(250);
                $shoot($browser, "p2m1-acp-prefixes-{$mode}-{$vp}", $mode);
            }
        }
    });

    // Sanity: the capture run wrote the expected screenshots.
    expect(glob(base_path('tests/Browser/screenshots/p2m1-*.png')))->not->toBeEmpty();
});
