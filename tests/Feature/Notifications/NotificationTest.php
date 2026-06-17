<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\WarningService;
use App\Forum\PostService;
use App\Mail\NotificationMail;
use App\Models\EmailSuppression;
use App\Models\Forum;
use App\Models\NotificationPreference;
use App\Models\Topic;
use App\Models\User;
use App\Models\WarningType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Notifications (data-model §7): merge-aware in-app (db) + queued email, per-event/channel prefs, suppression,
| @mention parsing, and the held→approved timing. Baseline: db queue + polling; no Reverb.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->seed();
});

function notifTopic(User $author): Topic
{
    $forum = Forum::create(['slug' => 'f'.$author->getKey(), 'title' => 'F', 'type' => 'forum']);

    return app(PostService::class)->createTopic($author, $forum, 'A topic', 'tiptap_json', Content::doc('op'));
}

it('notifies the topic author of a reply', function () {
    $author = Users::inGroups(['members', 'tl1']);
    $topic = notifTopic($author);

    app(PostService::class)->reply(Users::inGroups(['members', 'tl1']), $topic, 'tiptap_json', Content::doc('hi'));

    expect($author->notifications()->count())->toBe(1);
    expect($author->notifications()->first()->data['event'])->toBe('reply');
});

it('merges multiple replies into one notification for the author', function () {
    $author = Users::inGroups(['members', 'tl1']);
    $topic = notifTopic($author);

    app(PostService::class)->reply(Users::inGroups(['members', 'tl1']), $topic, 'tiptap_json', Content::doc('r1'));
    app(PostService::class)->reply(Users::inGroups(['members', 'tl1']), $topic, 'tiptap_json', Content::doc('r2'));

    expect($author->notifications()->count())->toBe(1); // merged, not stacked
    $data = $author->notifications()->first()->data;
    expect($data['count'])->toBe(2);
    expect($data['actors'])->toHaveCount(2);
});

it('notifies a mentioned user', function () {
    $author = Users::inGroups(['members', 'tl1']);
    $bob = Users::inGroups(['members'], ['username' => 'bob']);
    $topic = notifTopic($author);

    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'hey '],
        ['type' => 'mention', 'attrs' => ['id' => $bob->id, 'label' => 'bob']],
    ]]]];
    app(PostService::class)->reply(Users::inGroups(['members', 'tl1']), $topic, 'tiptap_json', $doc);

    expect($bob->notifications()->where('type', 'mention')->count())->toBe(1);
});

it('caps the @mention notification fan-out to the configured limit (P5.1)', function () {
    config(['novfora.antispam.mention_fanout_cap' => 3]);
    $author = Users::inGroups(['members', 'tl1']);
    $topic = notifTopic($author);

    // A single post mentioning far more distinct users than the cap (the mention-bomb shape).
    $targets = collect(range(1, 8))->map(fn ($i) => Users::inGroups(['members'], ['username' => "m{$i}"]));
    $nodes = $targets->map(fn ($u) => ['type' => 'mention', 'attrs' => ['id' => $u->id, 'label' => $u->username]])->all();
    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $nodes]]];

    app(PostService::class)->reply(Users::inGroups(['members', 'tl1']), $topic, 'tiptap_json', $doc);

    // Only the first `cap` (3) distinct mentioned users were notified — not all 8.
    $notified = $targets->filter(fn ($u) => $u->notifications()->where('type', 'mention')->exists())->count();
    expect($notified)->toBe(3);
});

it('does not notify a user replying to their own topic', function () {
    $author = Users::inGroups(['members', 'tl1']);
    $topic = notifTopic($author);

    app(PostService::class)->reply($author, $topic, 'tiptap_json', Content::doc('self reply'));

    expect($author->notifications()->count())->toBe(0);
});

it('respects a disabled channel preference', function () {
    $author = Users::inGroups(['members', 'tl1']);
    NotificationPreference::create(['user_id' => $author->id, 'event_type' => 'reply', 'channel' => 'database', 'enabled' => false]);
    $topic = notifTopic($author);

    app(PostService::class)->reply(Users::inGroups(['members', 'tl1']), $topic, 'tiptap_json', Content::doc('hi'));

    expect($author->notifications()->count())->toBe(0);
});

it('queues an email for a reply by default', function () {
    $author = Users::inGroups(['members', 'tl1']);
    $topic = notifTopic($author);

    app(PostService::class)->reply(Users::inGroups(['members', 'tl1']), $topic, 'tiptap_json', Content::doc('hi'));

    Mail::assertQueued(NotificationMail::class);
});

it('skips email for a suppressed address but still notifies in-app', function () {
    $author = Users::inGroups(['members', 'tl1']);
    EmailSuppression::create(['email' => $author->email, 'reason' => 'bounce', 'created_at' => now()]);
    $topic = notifTopic($author);

    app(PostService::class)->reply(Users::inGroups(['members', 'tl1']), $topic, 'tiptap_json', Content::doc('hi'));

    expect($author->notifications()->count())->toBe(1);
    Mail::assertNotQueued(NotificationMail::class);
});

it('notifies only when a held reply is approved (not while pending)', function () {
    $author = Users::inGroups(['members', 'tl1']);
    $topic = notifTopic($author);
    $reply = app(PostService::class)->reply(Users::inGroups(['members', 'tl0']), $topic, 'tiptap_json', Content::doc('held'));
    expect($reply->approved_state)->toBe('pending');
    expect($author->notifications()->count())->toBe(0);

    $this->actingAs(Users::inGroups(['moderators']))->post(route('posts.approve', $reply))->assertRedirect();

    expect($author->notifications()->count())->toBe(1);
});

it('notifies a member when they receive a warning', function () {
    $target = Users::inGroups(['members', 'tl2'], ['trust_level' => 2]);

    app(WarningService::class)->issue(Users::inGroups(['moderators']), $target, WarningType::where('slug', 'spam')->firstOrFail());

    expect($target->notifications()->where('type', 'moderation')->count())->toBe(1);
});
