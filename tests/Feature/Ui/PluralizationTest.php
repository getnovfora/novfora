<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Html;
use Tests\Support\Users;

/*
| Count suffixes must agree in number with the count they follow: "1 topic" (not "1 topics"), "1 view"
| (not "1 views"), "1 item awaiting review" (not "1 item(s)"). BUG-007 (forum-row topics/posts), BUG-008
| (trending views), BUG-015 (moderation queue/reports — drops the lazy "(s)" pattern).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

describe('the pluralization rules behave per-count', function () {
    it('chooses singular at 1 and plural at 0/2 for every count suffix', function () {
        // forum-row (BUG-007) — translatable lang keys.
        expect(trans_choice('forum.topics', 1))->toBe('topic')
            ->and(trans_choice('forum.topics', 2))->toBe('topics')
            ->and(trans_choice('forum.topics', 0))->toBe('topics')
            ->and(trans_choice('forum.posts', 1))->toBe('post')
            ->and(trans_choice('forum.posts', 2))->toBe('posts');

        // trending views (BUG-008) — Str::plural mirrors the sibling replies line.
        expect(Str::plural('view', 1))->toBe('view')
            ->and(Str::plural('view', 2))->toBe('views');

        // moderation (BUG-015) — inline pipe forms, no lang file.
        expect(trans_choice('item awaiting review|items awaiting review', 1))->toBe('item awaiting review')
            ->and(trans_choice('item awaiting review|items awaiting review', 2))->toBe('items awaiting review')
            ->and(trans_choice('open report|open reports', 1))->toBe('open report')
            ->and(trans_choice('open report|open reports', 0))->toBe('open reports');
    });
});

describe('the views render the right number form', function () {
    it('forum-row reads "1 topic"/"1 post" and "2 topics"/"2 posts" (BUG-007)', function () {
        $one = Forum::create(['slug' => 'one', 'title' => 'One', 'type' => 'forum', 'topic_count' => 1, 'post_count' => 1]);
        $two = Forum::create(['slug' => 'two', 'title' => 'Two', 'type' => 'forum', 'topic_count' => 2, 'post_count' => 2]);

        $singular = Html::visibleText(view('forum.partials.forum-row', ['forum' => $one])->render());
        expect($singular)->toContain('1 topic')->toContain('1 post')
            ->and($singular)->not->toContain('topics')->not->toContain('1 posts');

        $plural = Html::visibleText(view('forum.partials.forum-row', ['forum' => $two])->render());
        expect($plural)->toContain('2 topics')->toContain('2 posts');
    });

    it('trending topic line reads "1 view" and "2 views" (BUG-008)', function () {
        $forum = Forum::create(['slug' => 'f', 'title' => 'F', 'type' => 'forum']);
        $one = Topic::create(['forum_id' => $forum->id, 'slug' => 't1', 'title' => 'T1', 'view_count' => 1, 'reply_count' => 0]);
        $two = Topic::create(['forum_id' => $forum->id, 'slug' => 't2', 'title' => 'T2', 'view_count' => 2, 'reply_count' => 0]);

        $singular = Html::visibleText(view('discovery.partials.topic-line', ['topic' => $one->load('forum')])->render());
        expect($singular)->toContain('1 view')->and($singular)->not->toContain('1 views');

        $plural = Html::visibleText(view('discovery.partials.topic-line', ['topic' => $two->load('forum')])->render());
        expect($plural)->toContain('2 views');
    });

    it('moderation dashboard drops the lazy "(s)" pattern (BUG-015)', function () {
        $moderator = Users::inGroups(['moderators']);

        // Seeded fresh: no pending items, no open reports → the n=0 plural forms render.
        $text = Html::visibleText(
            $this->actingAs($moderator)->get(route('moderation.dashboard'))->assertOk()->getContent()
        );

        expect($text)->toContain('0 items awaiting review')
            ->toContain('0 open reports')
            ->and($text)->not->toContain('item(s)')
            ->not->toContain('report(s)');
    });
});
