<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Member-experience polish (Pillar 3, Slice 5): de-weighted Delete (danger-soft), the topic-list unread
| affordance (M2 surface — one batched TopicRead query), and the notification bell dropdown (M4) with its
| lazy load and shared club-visibility re-gate.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('adds a quiet danger-soft button variant (text-only until hover, no border) for de-weighted Delete', function () {
    $html = Blade::render('<x-ui.button variant="danger-soft">Delete</x-ui.button>');

    expect($html)
        ->toContain('text-danger')
        ->toContain('hover:bg-danger-soft')
        ->not->toContain('border border-line'); // unlike danger-ghost, it carries no border
});

it('renders Delete with the de-weighted danger-soft variant on a post the viewer owns', function () {
    $author = Users::inGroups(['members', 'tl2'], ['username' => 'poster', 'email' => 'poster@p.test']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'My topic', 'tiptap_json', Content::doc('Opening post.'));

    $this->actingAs($author)
        ->get(route('topics.show', $topic))
        ->assertOk()
        ->assertSee('hover:bg-danger-soft', false)       // the danger-soft Delete control
        ->assertSee('hover:text-danger-ink', false);
});

it('marks an unread topic on the board for a signed-in viewer (M2), and shows nothing to guests', function () {
    $author = Users::inGroups(['members', 'tl2'], ['username' => 'starter', 'email' => 'starter@b.test']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    app(PostService::class)->createTopic($author, $forum, 'Fresh topic', 'tiptap_json', Content::doc('Opening post.'));

    // Guests carry no per-topic read state → no unread markers leak into the board. (Asserted FIRST, while
    // still unauthenticated — actingAs() would otherwise persist across the next request.)
    $this->get(route('forums.show', $forum))->assertOk()->assertDontSee(__('forum.unread'));

    // A different member who has never opened it → the row shows the unread affordance.
    $viewer = Users::inGroups(['members', 'tl1'], ['username' => 'viewer', 'email' => 'viewer@b.test']);
    $this->actingAs($viewer)->get(route('forums.show', $forum))->assertOk()->assertSee(__('forum.unread'));
});

it('the notification bell lazy-loads recent notifications on open and keeps the unread count', function () {
    $author = Users::inGroups(['members', 'tl2'], ['username' => 'bell', 'email' => 'bell@n.test']);
    $forum = Forum::create(['slug' => 'bf', 'title' => 'BF', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'A topic', 'tiptap_json', Content::doc('op'));
    app(PostService::class)->reply(Users::inGroups(['members', 'tl1'], ['username' => 'rep', 'email' => 'rep@n.test']), $topic, 'tiptap_json', Content::doc('hi'));

    Livewire::actingAs($author->fresh())
        ->test('notification-bell')
        ->assertSet('count', 1)            // the badge count (unchanged behaviour)
        ->assertSet('recent', [])          // nothing loaded on the per-page render (query budget)
        ->call('loadRecent')
        ->assertSee('replied in');         // the dropdown summary line (BETA-1: reloads on EVERY open now)
});
