<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| ADR-0079 i18n guard for the forum domain: every key the views reference must resolve (trans($k) !== $k) and a
| rendered forum/topic page must show English with NO raw "forum.*" token leaking onto the screen (the
| externalization regression these guards exist to catch). The broad forum feature suite is the byte-for-byte
| safety net; this is the focused token guard.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('resolves the forum-domain keys instead of the raw token', function () {
    $keys = [
        'new_topic', 'start_a_topic', 'sub_boards', 'no_replies_yet', 'pinned', 'locked', 'pin', 'unpin',
        'lock', 'unlock', 'report', 'recycle_bin', 'related_topics', 'sign_in_to_reply', 'col_subject',
        'col_replies', 'col_views', 'col_last_post', 'empty_topics_title', 'no_forums_title',
    ];

    foreach ($keys as $key) {
        expect(trans("forum.{$key}"))->not->toBe("forum.{$key}");
    }

    expect(trans('common.edit'))->toBe('Edit');
    expect(trans('common.delete'))->toBe('Delete');
    expect(trans('common.forums'))->toBe('Forums');
});

it('renders a board view in English with no raw forum.* token', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General Chat', 'type' => 'forum']);
    Topic::create(['slug' => 'hello', 'title' => 'Hello World Topic', 'forum_id' => $forum->id, 'approved_state' => 'approved', 'last_posted_at' => now()]);

    $html = $this->get(route('forums.show', $forum))->assertOk()->getContent();

    expect($html)->not->toContain('forum.');   // no un-resolved key leaked to the page
    $this->get(route('forums.show', $forum))
        ->assertSee('Subject')                  // info-rich table header (default board style)
        ->assertSee('Replies')
        ->assertSee('Hello World Topic');
});

it('renders a topic view in English with no raw forum.* token', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General Chat', 'type' => 'forum']);
    $topic = Topic::create(['slug' => 'hello', 'title' => 'Hello World Topic', 'forum_id' => $forum->id, 'approved_state' => 'approved', 'last_posted_at' => now()]);

    $html = $this->get(route('topics.show', $topic))->assertOk()->getContent();

    expect($html)->not->toContain('forum.');
    expect($html)->toContain('Sign in to reply'); // guest reply CTA
});
