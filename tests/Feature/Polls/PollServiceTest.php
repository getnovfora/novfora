<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PollService;
use App\Forum\PollVoteException;
use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| Poll vote integrity (P2-M1, amendment #5). Single-choice = one option per user (replaces prior); multi-choice
| = 1..max_choices distinct options of THIS poll (replaces the set); a closed poll rejects votes; an option from
| another poll is rejected. Option tallies are recomputed authoritatively from poll_votes (drift-free). The
| topics.poll_id seam is wired on creation.
*/

uses(RefreshDatabase::class);

function makeTopic(): Topic
{
    $forum = Forum::firstOrCreate(['slug' => 'general'], ['title' => 'General', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl2'], ['username' => 'pollauthor'.fake()->unique()->numberBetween(1, 1_000_000), 'email' => fake()->unique()->safeEmail()]);

    return app(PostService::class)->createTopic($author, $forum, 'Poll topic', 'markdown', ['source' => 'Body.']);
}

beforeEach(function () {
    $this->seed();
    $this->service = app(PollService::class);
    $this->author = Users::inGroups(['members', 'tl2'], ['username' => 'author', 'email' => 'author@poll.test']);
    $this->voter = Users::inGroups(['members', 'tl2'], ['username' => 'voter', 'email' => 'voter@poll.test']);
    $forum = Forum::firstOrCreate(['slug' => 'general'], ['title' => 'General', 'type' => 'forum']);
    $this->topic = app(PostService::class)->createTopic($this->author, $forum, 'Poll topic', 'markdown', ['source' => 'Body.']);
});

it('creates a poll, its options, and wires the topics.poll_id seam', function () {
    $poll = $this->service->createPoll($this->author, $this->topic, 'Best language?', ['PHP', 'Go', 'Rust']);
    expect($poll->options)->toHaveCount(3);
    expect((int) $this->topic->fresh()->poll_id)->toBe((int) $poll->id);
});

it('rejects a poll with fewer than two distinct options', function () {
    $this->service->createPoll($this->author, $this->topic, 'Q?', ['Only one']);
})->throws(InvalidArgumentException::class);

it('dedupes option labels and strips markup', function () {
    $poll = $this->service->createPoll($this->author, $this->topic, 'Q?', ['<b>PHP</b>', 'PHP', 'Go']);
    expect($poll->options->pluck('label')->all())->toBe(['PHP', 'Go']);
});

it('single-choice: a second vote replaces the first', function () {
    $poll = $this->service->createPoll($this->author, $this->topic, 'Q?', ['A', 'B', 'C']);
    [$a, $b] = $poll->options->all();

    $this->service->vote($this->voter, $poll, [$a->id]);
    expect(PollOption::find($a->id)->vote_count)->toBe(1);

    $this->service->vote($this->voter, $poll, [$b->id]); // replace
    expect(PollOption::find($a->id)->vote_count)->toBe(0)
        ->and(PollOption::find($b->id)->vote_count)->toBe(1)
        ->and(PollVote::where('poll_id', $poll->id)->where('user_id', $this->voter->id)->count())->toBe(1);
});

it('single-choice: rejects submitting more than one option', function () {
    $poll = $this->service->createPoll($this->author, $this->topic, 'Q?', ['A', 'B']);
    $ids = $poll->options->pluck('id')->all();
    $this->service->vote($this->voter, $poll, $ids);
})->throws(PollVoteException::class);

it('multi-choice: accepts a set within max_choices and replaces it on revote', function () {
    $poll = $this->service->createPoll($this->author, $this->topic, 'Q?', ['A', 'B', 'C'], isMultiple: true, maxChoices: 2);
    [$a, $b, $c] = $poll->options->all();

    $this->service->vote($this->voter, $poll, [$a->id, $b->id]);
    expect(PollOption::find($a->id)->vote_count)->toBe(1)->and(PollOption::find($b->id)->vote_count)->toBe(1);

    $this->service->vote($this->voter, $poll, [$c->id]); // replace the whole set
    expect(PollOption::find($a->id)->vote_count)->toBe(0)
        ->and(PollOption::find($b->id)->vote_count)->toBe(0)
        ->and(PollOption::find($c->id)->vote_count)->toBe(1);
});

it('multi-choice: rejects exceeding max_choices', function () {
    $poll = $this->service->createPoll($this->author, $this->topic, 'Q?', ['A', 'B', 'C'], isMultiple: true, maxChoices: 2);
    $ids = $poll->options->pluck('id')->all();
    $this->service->vote($this->voter, $poll, $ids); // 3 > 2
})->throws(PollVoteException::class);

it('rejects voting on a closed poll', function () {
    $poll = $this->service->createPoll($this->author, $this->topic, 'Q?', ['A', 'B']);
    $this->service->close($this->author, $poll);
    $this->service->vote($this->voter, $poll, [$poll->options->first()->id]);
})->throws(PollVoteException::class);

it('rejects an option that belongs to another poll', function () {
    $poll = $this->service->createPoll($this->author, $this->topic, 'Q?', ['A', 'B']);
    $otherPoll = $this->service->createPoll($this->author, makeTopic(), 'Other?', ['X', 'Y']);
    $foreign = $otherPoll->options->first()->id;
    $this->service->vote($this->voter, $poll, [$foreign]);
})->throws(PollVoteException::class);

it('tallies multiple voters authoritatively', function () {
    $poll = $this->service->createPoll($this->author, $this->topic, 'Q?', ['A', 'B']);
    $a = $poll->options->first();
    $voter2 = Users::inGroups(['members', 'tl2'], ['username' => 'voter2', 'email' => 'voter2@poll.test']);

    $this->service->vote($this->voter, $poll, [$a->id]);
    $this->service->vote($voter2, $poll, [$a->id]);

    expect(PollOption::find($a->id)->vote_count)->toBe(2);
});

it('rejects an empty selection', function () {
    $poll = $this->service->createPoll($this->author, $this->topic, 'Q?', ['A', 'B']);
    $this->service->vote($this->voter, $poll, []);
})->throws(PollVoteException::class);

it('counts a multi-choice revoter as a single distinct voter (total_voters)', function () {
    $poll = $this->service->createPoll($this->author, $this->topic, 'Q?', ['A', 'B', 'C'], isMultiple: true, maxChoices: 3);
    [$a, $b, $c] = $poll->options->all();

    $this->service->vote($this->voter, $poll, [$a->id, $b->id]);
    $this->service->vote($this->voter, $poll, [$c->id]); // revote: still ONE voter

    expect($this->service->displayData($poll->fresh())['total_voters'])->toBe(1);
});
