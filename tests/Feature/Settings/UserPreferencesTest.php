<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Consolidated display preferences (P2-M4). The ⚡user-preferences SFC writes the authenticated user only and
| validates against the allowed sets; TopicController honours both the page size and the reply sort order.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function prefForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

it('saves a viewer’s display preferences (own account only)', function () {
    $user = Users::inGroups(['members', 'tl1']);

    Livewire::actingAs($user)->test('settings.user-preferences')
        ->set('postsPerPage', 30)
        ->set('threadSort', 'newest')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $user->fresh();
    expect((int) $fresh->posts_per_page)->toBe(30)
        ->and($fresh->thread_sort)->toBe('newest')
        ->and($fresh->postsPerPage())->toBe(30)
        ->and($fresh->threadSortNewestFirst())->toBeTrue();
});

it('clamps an out-of-range page size and an unknown sort to the defaults', function () {
    $user = Users::inGroups(['members', 'tl1']);

    Livewire::actingAs($user)->test('settings.user-preferences')
        ->set('postsPerPage', 999)   // not in {15,30,50}
        ->set('threadSort', 'sideways')
        ->call('save');

    $fresh = $user->fresh();
    expect((int) $fresh->posts_per_page)->toBe(User::POSTS_PER_PAGE_DEFAULT)
        ->and($fresh->thread_sort)->toBe('oldest');
});

it('requires authentication to reach the preferences page', function () {
    $this->get(route('settings.preferences'))->assertRedirect();
});

it('paginates a thread by the viewer’s posts-per-page preference', function () {
    $forum = prefForum();
    $author = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Long thread', 'tiptap_json', Content::doc('op'));
    for ($i = 0; $i < 20; $i++) {
        app(PostService::class)->reply($author, $topic, 'tiptap_json', Content::doc("reply {$i}"));
    }

    $viewer = Users::inGroups(['members', 'tl1']);
    $viewer->forceFill(['posts_per_page' => 30])->save();

    $response = $this->actingAs($viewer)->get(route('topics.show', $topic))->assertOk();
    expect($response->viewData('posts')->perPage())->toBe(30)
        ->and($response->viewData('posts')->count())->toBe(21); // OP + 20 replies on one page
});

it('orders a thread newest-first when the viewer prefers it', function () {
    $forum = prefForum();
    $author = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Sorted', 'tiptap_json', Content::doc('op'));
    $last = app(PostService::class)->reply($author, $topic, 'tiptap_json', Content::doc('the final reply'));

    $viewer = Users::inGroups(['members', 'tl1']);
    $viewer->forceFill(['thread_sort' => 'newest'])->save();

    $response = $this->actingAs($viewer)->get(route('topics.show', $topic))->assertOk();
    expect((int) $response->viewData('posts')->first()->id)->toBe((int) $last->id);
});
