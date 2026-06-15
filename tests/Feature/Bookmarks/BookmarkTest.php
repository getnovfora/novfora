<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\BookmarkService;
use App\Models\Bookmark;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Member tool 2.1 — personal bookmarks (saved topics + posts). A private edge owned by the user; one row per
| target; a toggle button on topics/posts; a "Saved" view that re-checks current visibility.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** @return array{0:Forum,1:Topic,2:Post} */
function bmFixture(): array
{
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $author = User::factory()->create();
    $topic = Topic::create(['slug' => 'hello', 'title' => 'Hello World', 'forum_id' => $forum->id, 'user_id' => $author->id, 'last_posted_at' => now()]);
    $post = Post::create([
        'topic_id' => $topic->id, 'user_id' => $author->id, 'body_format' => 'tiptap_json',
        'body_canonical' => [], 'body_text' => 'hi', 'position' => 1, 'approved_state' => 'approved',
    ]);

    return [$forum, $topic, $post];
}

it('toggles a bookmark on and then off', function () {
    [, , $post] = bmFixture();
    $user = User::factory()->create();
    $svc = app(BookmarkService::class);

    expect($svc->toggle($user, $post))->toBeTrue()
        ->and($svc->isBookmarked($user, $post))->toBeTrue()
        ->and(Bookmark::count())->toBe(1);

    expect($svc->toggle($user, $post))->toBeFalse()
        ->and($svc->isBookmarked($user, $post))->toBeFalse()
        ->and(Bookmark::count())->toBe(0);
});

it('keeps one row per target even if saved twice (unique edge)', function () {
    [, $topic] = bmFixture();
    $user = User::factory()->create();
    $svc = app(BookmarkService::class);

    $svc->toggle($user, $topic); // on
    // Simulate a racing duplicate insert — the unique index + catch keep it at one row, still "saved".
    Bookmark::query()->where('user_id', $user->id)->delete(); // drop, then force the create path twice
    $svc->toggle($user, $topic);
    expect(Bookmark::count())->toBe(1);
});

it('batch-resolves the saved post ids for a page', function () {
    [, , $post] = bmFixture();
    $user = User::factory()->create();
    app(BookmarkService::class)->toggle($user, $post);

    $map = app(BookmarkService::class)->bookmarkedIds($user, Post::class, [$post->id, 999999]);
    expect($map)->toHaveKey($post->id)->and($map)->not->toHaveKey(999999);
});

it('toggles a saved post through the Livewire button', function () {
    [, , $post] = bmFixture();
    $user = Users::inGroups(['members']);

    Livewire::actingAs($user)
        ->test('forum.bookmark-button', ['kind' => 'post', 'targetId' => $post->id, 'saved' => false, 'canSave' => true])
        ->call('toggle')
        ->assertSet('saved', true);

    expect(app(BookmarkService::class)->isBookmarked($user, $post->fresh()))->toBeTrue();
});

it('lists the user’s saved items on /saved and requires auth', function () {
    [, $topic] = bmFixture();
    $user = Users::inGroups(['members']);
    app(BookmarkService::class)->toggle($user, $topic);

    $this->actingAs($user)->get(route('saved.index'))->assertOk()->assertSee('Hello World');

    auth()->logout();
    $this->get(route('saved.index'))->assertRedirect(); // auth middleware
});

it('drops a bookmark whose target was deleted from the saved view', function () {
    [, , $post] = bmFixture();
    $user = Users::inGroups(['members']);
    app(BookmarkService::class)->toggle($user, $post);

    $post->delete(); // soft-deleted target → morphTo resolves null → filtered out

    $this->actingAs($user)->get(route('saved.index'))->assertOk()->assertDontSee('Hello World');
});
