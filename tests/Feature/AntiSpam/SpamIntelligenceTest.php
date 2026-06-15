<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\Intelligence\SpamScorer;
use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\SpamAssessment;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Phase 4 · M6.1 (APEX untrusted-input) — the spam scorer HOLDS (never deletes). Signals: content similarity,
| burst, new-account, tl0. Trusted members are EXEMPT (the false-positive guard) and short repeated replies are
| never flagged as duplicates.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function spamForum(): Forum
{
    return Forum::create(['slug' => 'gen-'.bin2hex(random_bytes(3)), 'title' => 'Gen', 'type' => 'forum']);
}

function spamTopic(Forum $forum, User $user): Topic
{
    return Topic::create(['slug' => 't-'.bin2hex(random_bytes(3)), 'title' => 'T', 'forum_id' => $forum->id, 'user_id' => $user->id]);
}

function authorPost(Topic $topic, User $user, string $body): Post
{
    return Post::create([
        'topic_id' => $topic->id,
        'user_id' => $user->id,
        'body_format' => 'tiptap_json',
        'body_canonical' => Content::doc($body),
        'body_html_cache' => '<p>'.e($body).'</p>',
        'body_text' => $body,
        'position' => 1,
        'approved_state' => 'approved',
    ]);
}

// ── False-positive guards (the most important behaviour) ─────────────────────────────────────────────────

it('exempts a trusted member from scoring (FP guard) even with duplicate + burst', function () {
    $forum = spamForum();
    $author = Users::inGroups(['members', 'tl3'], ['email' => 'tl3@intel.test']);
    $topic = spamTopic($forum, $author);
    authorPost($topic, $author, 'identical repeated content block right here now');
    for ($i = 0; $i < 6; $i++) {
        authorPost($topic, $author, "burst {$i} content");
    }

    $score = app(SpamScorer::class)->score($author->fresh(), 'identical repeated content block right here now');

    expect($score->held)->toBeFalse();
    expect($score->score)->toBe(0);
});

it('exempts an established member by post count', function () {
    $forum = spamForum();
    $author = Users::inGroups(['members', 'tl1'], ['email' => 'estab@intel.test', 'post_count' => 60]);
    $topic = spamTopic($forum, $author);
    authorPost($topic, $author, 'repeated content that would otherwise be flagged here');

    $score = app(SpamScorer::class)->score($author->fresh(), 'repeated content that would otherwise be flagged here');

    expect($score->held)->toBeFalse();
});

it('does not hold a new member’s first normal post', function () {
    $author = Users::inGroups(['members', 'tl0'], ['email' => 'first@intel.test']);

    $score = app(SpamScorer::class)->score($author, 'just saying hello to everyone here for the very first time');

    expect($score->held)->toBeFalse(); // tl0 + new_account = 2 < hold threshold 3
});

it('does not flag a short repeated reply as a duplicate', function () {
    $forum = spamForum();
    $author = Users::inGroups(['members', 'tl0'], ['email' => 'short@intel.test']);
    $topic = spamTopic($forum, $author);
    authorPost($topic, $author, 'thanks');

    $score = app(SpamScorer::class)->score($author->fresh(), 'thanks');

    expect($score->signals)->not->toHaveKey('similarity'); // too short to fingerprint
});

// ── Positive detections ──────────────────────────────────────────────────────────────────────────────────

it('holds a new member reposting identical content (similarity)', function () {
    $forum = spamForum();
    $author = Users::inGroups(['members', 'tl0'], ['email' => 'dup0@intel.test']);
    $topic = spamTopic($forum, $author);
    authorPost($topic, $author, 'spam spam buy now at the cheap discount link here');

    $score = app(SpamScorer::class)->score($author->fresh(), 'spam spam buy now at the cheap discount link here');

    expect($score->held)->toBeTrue();
    expect($score->signals)->toHaveKey('similarity');
});

it('holds a bursting new member', function () {
    $forum = spamForum();
    $author = Users::inGroups(['members', 'tl0'], ['email' => 'burst@intel.test']);
    $topic = spamTopic($forum, $author);
    for ($i = 0; $i < 5; $i++) {
        authorPost($topic, $author, "post number {$i} with some unique content xyz");
    }

    $score = app(SpamScorer::class)->score($author->fresh(), 'a fresh and entirely different message');

    expect($score->held)->toBeTrue();
    expect($score->signals)->toHaveKey('burst');
});

// ── Pipeline integration: HOLD only, assessment recorded, never deleted ──────────────────────────────────

it('holds a reposted duplicate through the pipeline and records the assessment (never deletes)', function () {
    $forum = spamForum();
    $author = Users::inGroups(['members', 'tl1'], ['email' => 'dupe@intel.test']);
    $topic = spamTopic($forum, $author);
    authorPost($topic, $author, 'buy discount widgets at spam dot example right now');

    $reply = app(PostService::class)->reply($author->fresh(), $topic->fresh(), 'tiptap_json', Content::doc('buy discount widgets at spam dot example right now'));

    expect($reply->approved_state)->toBe('pending');      // HELD …
    expect(Post::find($reply->id))->not->toBeNull();      // … never auto-deleted

    $assessment = SpamAssessment::where('post_id', $reply->id)->first();
    expect($assessment)->not->toBeNull();
    expect($assessment->reasons)->toContain('spam:similarity');
    expect($assessment->score)->toBeGreaterThanOrEqual(3);
});

it('does not hold a trusted member reposting the same content', function () {
    $forum = spamForum();
    $author = Users::inGroups(['members', 'tl4'], ['email' => 'trusted@intel.test']);
    $topic = spamTopic($forum, $author);
    authorPost($topic, $author, 'here is my standard greeting message for everyone today');

    $reply = app(PostService::class)->reply($author->fresh(), $topic->fresh(), 'tiptap_json', Content::doc('here is my standard greeting message for everyone today'));

    expect($reply->approved_state)->toBe('approved');
    expect(SpamAssessment::where('post_id', $reply->id)->exists())->toBeFalse();
});
