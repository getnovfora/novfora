<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Moderation fence (ADR-0108): a topic whose opening post is pending moderation (ADR-0007 §2.4 — the topic
| inherits its OP's state) must NOT be reachable at its direct URL or via its Atom feed by anyone except its
| author and staff who can moderate — and even for those two, the SEO head block (canonical, description,
| OG, twitter, feed link, JSON-LD) stays suppressed until the topic is approved. Forbidden viewers get 404,
| never 403 (the FeedController/sitemap no-disclosure idiom).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/**
 * A TL0-held topic on a public board (mirrors PostModerationTest's new-user-queue idiom): the opening post
 * — and with it the topic — lands in approved_state 'pending'.
 *
 * @return array{0: User, 1: Topic}
 */
function pendingFenceTopic(): array
{
    $forum = Forum::firstOrCreate(['slug' => 'fence-board'], ['title' => 'Fence Board', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl0']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Held Fence Topic', 'tiptap_json', Content::doc('held fence body text'));

    expect($topic->fresh()->approved_state)->toBe('pending');

    return [$author, $topic->fresh()];
}

it('404s a pending topic at its direct URL for a guest', function () {
    [, $topic] = pendingFenceTopic();

    $this->get(route('topics.show', $topic))->assertNotFound();
});

it('404s a pending topic at its direct URL for an unrelated member', function () {
    [, $topic] = pendingFenceTopic();

    $this->actingAs(Users::inGroups(['members']))->get(route('topics.show', $topic))->assertNotFound();
});

it('shows a pending topic to its author WITHOUT the SEO head block', function () {
    [$author, $topic] = pendingFenceTopic();

    $this->actingAs($author)->get(route('topics.show', $topic))
        ->assertOk()
        ->assertSee('Held Fence Topic') // the page itself renders for the author…
        ->assertDontSee('og:title', false) // …but nothing crawlable/sharable does
        ->assertDontSee('rel="canonical"', false)
        ->assertDontSee('DiscussionForumPosting', false);
});

it('shows a pending topic to a moderator WITHOUT the SEO head block', function () {
    [, $topic] = pendingFenceTopic();

    $this->actingAs(Users::inGroups(['moderators']))->get(route('topics.show', $topic))
        ->assertOk()
        ->assertDontSee('og:title', false)
        ->assertDontSee('rel="canonical"', false)
        ->assertDontSee('DiscussionForumPosting', false);
});

it('404s a pending topic’s Atom feed outright', function () {
    [, $topic] = pendingFenceTopic();

    $this->get(route('feeds.topic', $topic))->assertNotFound();
});

it('serves the page with OG and the feed once the topic is approved', function () {
    [, $topic] = pendingFenceTopic();

    Post::where('topic_id', $topic->getKey())->update(['approved_state' => 'approved']);
    $topic->forceFill(['approved_state' => 'approved'])->save();

    $this->get(route('topics.show', $topic))
        ->assertOk()
        ->assertSee('og:title', false)
        ->assertSee('held fence body text') // the OP excerpt now backs the meta description
        ->assertSee('DiscussionForumPosting', false);

    $this->get(route('feeds.topic', $topic))->assertOk()->assertSee('Held Fence Topic');
});
