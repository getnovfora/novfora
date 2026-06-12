<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\BulkModerationService;
use App\Forum\PostService;
use App\Models\AuditLog;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Cross-page bulk moderation (P2-M4 ◐). The contract under test is the RANK GUARD: a bulk action applies to
| every eligible item and SILENTLY SKIPS items whose author out-ranks the actor — never erroring — and BOTH
| sets land in the audit log. A non-staff caller can action nothing (the forum gate is enforced in the service).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function bulkForum(string $slug = 'general'): Forum
{
    return Forum::create(['slug' => $slug, 'title' => ucfirst($slug), 'type' => 'forum']);
}

it('bulk-deletes posts a moderator outranks, silently skipping a higher-ranked author and auditing both', function () {
    $forum = bulkForum();
    $posts = app(PostService::class);
    $topic = $posts->createTopic(Users::inGroups(['members']), $forum, 'T', 'tiptap_json', Content::doc('op'));
    $memberPost = $posts->reply(Users::inGroups(['members']), $topic, 'tiptap_json', Content::doc('member reply'));
    $adminPost = $posts->reply(Users::inGroups(['admins']), $topic, 'tiptap_json', Content::doc('admin reply'));

    $result = app(BulkModerationService::class)
        ->deletePosts(Users::inGroups(['moderators']), [(int) $memberPost->id, (int) $adminPost->id]);

    expect($result['applied'])->toBe([(int) $memberPost->id])
        ->and($result['skipped'])->toBe([(int) $adminPost->id]);

    // The member's post is gone; the admin's post survives (the moderator cannot out-rank an admin).
    expect(Post::find($memberPost->id))->toBeNull()
        ->and(Post::find($adminPost->id))->not->toBeNull();

    // Both sets are audited.
    $audit = AuditLog::where('action', 'bulk.posts.deleted')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->changes['applied'])->toBe([(int) $memberPost->id])
        ->and($audit->changes['skipped'])->toBe([(int) $adminPost->id]);
});

it('lets an admin bulk-act on everyone, including other admins', function () {
    $forum = bulkForum();
    $posts = app(PostService::class);
    $topic = $posts->createTopic(Users::inGroups(['members']), $forum, 'T', 'tiptap_json', Content::doc('op'));
    $modPost = $posts->reply(Users::inGroups(['moderators']), $topic, 'tiptap_json', Content::doc('mod reply'));
    $adminPost = $posts->reply(Users::inGroups(['admins']), $topic, 'tiptap_json', Content::doc('admin reply'));

    $result = app(BulkModerationService::class)
        ->deletePosts(Users::inGroups(['admins']), [(int) $modPost->id, (int) $adminPost->id]);

    expect($result['skipped'])->toBe([])
        ->and(count($result['applied']))->toBe(2);
});

it('applies no post action for a non-staff member (forum gate enforced in the service)', function () {
    $forum = bulkForum();
    $posts = app(PostService::class);
    $topic = $posts->createTopic(Users::inGroups(['members']), $forum, 'T', 'tiptap_json', Content::doc('op'));
    $reply = $posts->reply(Users::inGroups(['members']), $topic, 'tiptap_json', Content::doc('reply'));

    $result = app(BulkModerationService::class)
        ->deletePosts(Users::inGroups(['members']), [(int) $reply->id]);

    expect($result['applied'])->toBe([])
        ->and($result['skipped'])->toBe([(int) $reply->id])
        ->and(Post::find($reply->id))->not->toBeNull();
});

it('bulk-locks topics a moderator outranks, skipping a higher-ranked author topic', function () {
    $forum = bulkForum();
    $posts = app(PostService::class);
    $memberTopic = $posts->createTopic(Users::inGroups(['members']), $forum, 'Member topic', 'tiptap_json', Content::doc('op'));
    $adminTopic = $posts->createTopic(Users::inGroups(['admins']), $forum, 'Admin topic', 'tiptap_json', Content::doc('op'));

    $result = app(BulkModerationService::class)
        ->lockTopics(Users::inGroups(['moderators']), [(int) $memberTopic->id, (int) $adminTopic->id], true);

    expect($result['applied'])->toBe([(int) $memberTopic->id])
        ->and($result['skipped'])->toBe([(int) $adminTopic->id]);
    expect($memberTopic->fresh()->status)->toBe('locked')
        ->and($adminTopic->fresh()->status)->toBe('open');
});

it('bulk-moves eligible topics to another forum, skipping a no-op same-forum move', function () {
    $from = bulkForum('from');
    $to = bulkForum('to');
    $posts = app(PostService::class);
    $moving = $posts->createTopic(Users::inGroups(['members']), $from, 'Moving', 'tiptap_json', Content::doc('op'));
    $already = $posts->createTopic(Users::inGroups(['members']), $to, 'Already there', 'tiptap_json', Content::doc('op'));

    $result = app(BulkModerationService::class)
        ->moveTopics(Users::inGroups(['moderators']), [(int) $moving->id, (int) $already->id], (int) $to->id);

    expect($result['applied'])->toBe([(int) $moving->id])
        ->and($result['skipped'])->toBe([(int) $already->id]); // same-forum move is a no-op skip
    expect((int) $moving->fresh()->forum_id)->toBe((int) $to->id);
});

it('refuses a bulk move whose destination is a category, not a postable forum', function () {
    $category = Forum::create(['slug' => 'cat', 'title' => 'Cat', 'type' => 'category']);
    $forum = bulkForum();
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members']), $forum, 'T', 'tiptap_json', Content::doc('op'));

    // A forged moveTarget pointing at a CATEGORY id must NOT relocate topics under a non-postable container.
    $result = app(BulkModerationService::class)
        ->moveTopics(Users::inGroups(['moderators']), [(int) $topic->id], (int) $category->id);

    expect($result['applied'])->toBe([])
        ->and($result['skipped'])->toBe([(int) $topic->id]);
    expect((int) $topic->fresh()->forum_id)->toBe((int) $forum->id); // unchanged
});

it('bulk-deletes topics a moderator outranks, skipping a higher-ranked author topic', function () {
    $forum = bulkForum();
    $posts = app(PostService::class);
    $memberTopic = $posts->createTopic(Users::inGroups(['members']), $forum, 'Member topic', 'tiptap_json', Content::doc('op'));
    $adminTopic = $posts->createTopic(Users::inGroups(['admins']), $forum, 'Admin topic', 'tiptap_json', Content::doc('op'));

    $result = app(BulkModerationService::class)
        ->deleteTopics(Users::inGroups(['moderators']), [(int) $memberTopic->id, (int) $adminTopic->id]);

    expect($result['applied'])->toBe([(int) $memberTopic->id])
        ->and($result['skipped'])->toBe([(int) $adminTopic->id]);
    expect(Topic::find($memberTopic->id))->toBeNull()
        ->and(Topic::find($adminTopic->id))->not->toBeNull();
});
