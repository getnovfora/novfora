<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| The ⚡post-reactions component (P2-M1) — Livewire actions are public by default, so authorisation, type
| validation, and the per-trust rate limit must be re-asserted INSIDE react(), never trusted from the
| client-supplied mount state (canReact is #[Locked] but still re-checked server-side).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl2'], ['username' => 'author', 'email' => 'author@cmp.test']);
    $this->member = Users::inGroups(['members', 'tl2'], ['username' => 'member', 'email' => 'member@cmp.test']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'A topic', 'markdown', ['source' => 'Opening.']);
    $this->post = $topic->posts()->first();
});

it('lets a permitted member react via the component', function () {
    Livewire::actingAs($this->member)
        ->test('forum.post-reactions', ['postId' => $this->post->id, 'topicId' => $this->post->topic_id, 'canReact' => true])
        ->call('react', 'like')
        ->assertHasNoErrors();

    expect(Reaction::where('post_id', $this->post->id)->where('user_id', $this->member->id)->value('type'))->toBe('like');
});

it('re-asserts react.create at the action — a user without it is forbidden even if canReact was tampered true', function () {
    $stranger = User::factory()->create(); // no groups → react.create denies

    Livewire::actingAs($stranger)
        ->test('forum.post-reactions', ['postId' => $this->post->id, 'topicId' => $this->post->topic_id, 'canReact' => true])
        ->call('react', 'like')
        ->assertStatus(403);

    expect(Reaction::where('post_id', $this->post->id)->count())->toBe(0);
});

it('rejects a reaction whose post does not belong to the claimed topic (defense-in-depth)', function () {
    $otherTopic = app(PostService::class)->createTopic($this->member, $this->post->topic->forum, 'Other topic', 'markdown', ['source' => 'x']);

    Livewire::actingAs($this->member)
        ->test('forum.post-reactions', ['postId' => $this->post->id, 'topicId' => $otherTopic->id, 'canReact' => true])
        ->call('react', 'like')
        ->assertStatus(403);

    expect(Reaction::where('post_id', $this->post->id)->count())->toBe(0);
});

it('rejects an unknown reaction type at the action (422)', function () {
    Livewire::actingAs($this->member)
        ->test('forum.post-reactions', ['postId' => $this->post->id, 'topicId' => $this->post->topic_id, 'canReact' => true])
        ->call('react', 'rocket')
        ->assertStatus(422);
});

it('enforces the per-trust reaction rate limit', function () {
    config(['hearth.reactions.rate_limits' => ['default' => 1, 'tl0' => 1]]);

    $component = Livewire::actingAs($this->member)
        ->test('forum.post-reactions', ['postId' => $this->post->id, 'topicId' => $this->post->topic_id, 'canReact' => true]);

    $component->call('react', 'like')->assertHasNoErrors();        // within cap (adds 'like')
    $component->call('react', 'love')->assertHasErrors('reaction'); // over cap → error, no change

    expect(Reaction::where('post_id', $this->post->id)->where('user_id', $this->member->id)->value('type'))->toBe('like');
});

it('keeps the react action within a bounded query count (no N+1 on the action path)', function () {
    // The P2-M2 reaction notification (Reacted → SendReactionNotification) is a QUEUED listener — on the
    // baseline tier it is pushed to the DB queue and drained by cron, OFF this hot action path. Fake the
    // queue so the budget measures the synchronous action's own cost, mirroring production.
    Queue::fake();

    // Warm the permission cache so steady-state action cost is measured, not the cold resolver.
    Livewire::actingAs($this->member)
        ->test('forum.post-reactions', ['postId' => $this->post->id, 'topicId' => $this->post->topic_id, 'canReact' => true])
        ->call('react', 'like'); // ON

    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    Livewire::actingAs($this->member)
        ->test('forum.post-reactions', ['postId' => $this->post->id, 'topicId' => $this->post->topic_id, 'canReact' => true])
        ->call('react', 'love') // change like → love
        ->assertHasNoErrors();

    // The action loads the post once, resolves two (warm) perms, checks the rate limit (one relation read),
    // toggles + recounts both touched types + audits. A loose ceiling pins it against a per-something N+1.
    expect($count)->toBeLessThanOrEqual(15);
});
