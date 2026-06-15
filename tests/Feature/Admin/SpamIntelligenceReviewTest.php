<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Post;
use App\Models\SpamAssessment;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\AclVersion;
use App\Permissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Phase 4 · M6.2 — Admin → Spam intelligence review surface (staff-gated). Shows held posts with their score +
| signals; approve clears the hold, reject soft-deletes (never a hard delete).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function spamReviewAdmin(): User
{
    $admin = Users::withTwoFactor(Users::inGroups(['admins'], ['email' => 'spam-review@acp.test']));
    // Make the per-action topic.moderate check robust regardless of the admin preset.
    AclEntry::create(['permission_key' => 'topic.moderate', 'holder_type' => 'user', 'holder_id' => $admin->id, 'scope_type' => 'global', 'scope_id' => null, 'value' => 1]);
    app(AclVersion::class)->bump();
    app(PermissionResolver::class)->flushMemo();
    Cache::flush();

    return $admin->fresh();
}

function heldAssessment(string $title = 'Held Thread', string $body = 'spam content here for the review surface now'): SpamAssessment
{
    $forum = Forum::create(['slug' => 'gen-'.bin2hex(random_bytes(3)), 'title' => 'G', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl0'], ['email' => 'held-'.bin2hex(random_bytes(2)).'@spam.test']);
    $topic = Topic::create(['slug' => 't-'.bin2hex(random_bytes(3)), 'title' => $title, 'forum_id' => $forum->id, 'user_id' => $author->id]);
    $post = Post::create([
        'topic_id' => $topic->id, 'user_id' => $author->id, 'body_format' => 'tiptap_json',
        'body_canonical' => Content::doc($body), 'body_html_cache' => '<p>'.e($body).'</p>', 'body_text' => $body,
        'position' => 1, 'approved_state' => 'pending',
    ]);

    return SpamAssessment::create([
        'post_id' => $post->id, 'user_id' => $author->id, 'score' => 4,
        'signals' => ['similarity' => 3, 'tl0' => 1], 'reasons' => ['spam:similarity', 'spam:tl0'],
    ]);
}

it('forbids a non-admin from the spam-intelligence surface', function () {
    $member = Users::inGroups(['members', 'tl2'], ['email' => 'spam-not-admin@acp.test']);

    $this->actingAs($member)->get(route('admin.spam-intelligence'))->assertForbidden();
    Livewire::actingAs($member)->test('admin.spam-intelligence')->assertStatus(403);
});

it('lists held posts with their score and signals', function () {
    heldAssessment('Suspicious Thread');

    $html = Livewire::actingAs(spamReviewAdmin())->test('admin.spam-intelligence')->html();

    expect($html)->toContain('Suspicious Thread');
    expect($html)->toContain('similarity');
    expect($html)->toContain('4'); // the score
});

it('approves a held post and clears it from the queue', function () {
    $assessment = heldAssessment();
    $postId = $assessment->post_id;

    Livewire::actingAs(spamReviewAdmin())
        ->test('admin.spam-intelligence')
        ->call('approve', $assessment->id)
        ->assertHasNoErrors();

    expect(Post::find($postId)->approved_state)->toBe('approved');
});

it('rejects a held post by soft-deleting it (never a hard delete)', function () {
    $assessment = heldAssessment();
    $postId = $assessment->post_id;

    Livewire::actingAs(spamReviewAdmin())
        ->test('admin.spam-intelligence')
        ->call('reject', $assessment->id)
        ->assertHasNoErrors();

    expect(Post::withTrashed()->find($postId)->trashed())->toBeTrue();
    expect(Post::withTrashed()->find($postId)->approved_state)->toBe('rejected');
});
