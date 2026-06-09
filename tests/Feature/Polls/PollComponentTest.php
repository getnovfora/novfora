<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PollService;
use App\Forum\PostService;
use App\Models\Forum;
use App\Models\PollVote;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| The ⚡poll vote component + the create-topic poll composer (P2-M1). Livewire actions are public, so poll.vote
| (+ forum.view) is re-asserted inside vote(); integrity errors surface as a flash, never an exception.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $this->author = Users::inGroups(['members', 'tl3'], ['username' => 'author', 'email' => 'author@pollui.test']);
    $this->member = Users::inGroups(['members', 'tl2'], ['username' => 'member', 'email' => 'member@pollui.test']);
    $this->topic = app(PostService::class)->createTopic($this->author, $forum, 'Poll topic', 'markdown', ['source' => 'Body.']);
    $this->poll = app(PollService::class)->createPoll($this->author, $this->topic, 'Q?', ['A', 'B', 'C']);
});

function pollProps(Topic $topic, $poll, array $voted = []): array
{
    return [
        'pollId' => $poll->id,
        'topicId' => $topic->id,
        'poll' => app(PollService::class)->displayData($poll->fresh()),
        'voted' => $voted,
        'canVote' => true,
    ];
}

it('lets a member vote via the component (single choice)', function () {
    $optA = $this->poll->options->first();

    Livewire::actingAs($this->member)
        ->test('forum.poll', pollProps($this->topic, $this->poll))
        ->set('choice', $optA->id)
        ->call('vote')
        ->assertHasNoErrors();

    expect(PollVote::where('poll_id', $this->poll->id)->where('user_id', $this->member->id)->value('poll_option_id'))
        ->toBe((int) $optA->id);
});

it('re-asserts poll.vote at the action — a user without it is forbidden', function () {
    $stranger = User::factory()->create();

    Livewire::actingAs($stranger)
        ->test('forum.poll', pollProps($this->topic, $this->poll))
        ->set('choice', $this->poll->options->first()->id)
        ->call('vote')
        ->assertStatus(403);

    expect(PollVote::count())->toBe(0);
});

it('rejects a vote whose poll does not belong to the claimed topic (defense-in-depth)', function () {
    $otherTopic = app(PostService::class)->createTopic($this->author, $this->topic->forum, 'Other topic', 'markdown', ['source' => 'x']);

    Livewire::actingAs($this->member)
        ->test('forum.poll', [
            'pollId' => $this->poll->id,         // poll belongs to $this->topic …
            'topicId' => $otherTopic->id,        // … but the claimed topic is a different one
            'poll' => app(PollService::class)->displayData($this->poll),
            'voted' => [],
            'canVote' => true,
        ])
        ->set('choice', $this->poll->options->first()->id)
        ->call('vote')
        ->assertStatus(403);

    expect(PollVote::count())->toBe(0);
});

it('surfaces a vote error for a closed poll instead of throwing', function () {
    app(PollService::class)->close($this->author, $this->poll);

    Livewire::actingAs($this->member)
        ->test('forum.poll', pollProps($this->topic, $this->poll))
        ->set('choice', $this->poll->options->first()->id)
        ->call('vote')
        ->assertHasErrors('poll');
});

it('creates a poll through the create-topic composer', function () {
    Livewire::actingAs($this->author)
        ->test('forum.create-topic', ['forumId' => $this->topic->forum_id])
        ->set('title', 'A polled topic')
        ->set('format', 'markdown')
        ->set('markdownSource', 'Opening body.')
        ->set('addPoll', true)
        ->set('pollQuestion', 'Coffee or tea?')
        ->set('pollOptions', ['Coffee', 'Tea'])
        ->call('save');

    $topic = Topic::where('title', 'A polled topic')->first();
    expect($topic)->not->toBeNull()
        ->and($topic->poll)->not->toBeNull()
        ->and($topic->poll->options->pluck('label')->all())->toBe(['Coffee', 'Tea']);
});

it('does not offer poll composition to an author without poll.create (TL0)', function () {
    $tl0 = Users::inGroups(['members', 'tl0'], ['username' => 'newbie', 'email' => 'newbie@pollui.test']);

    Livewire::actingAs($tl0)
        ->test('forum.create-topic', ['forumId' => $this->topic->forum_id])
        ->assertSet('canCreatePoll', false);
});
