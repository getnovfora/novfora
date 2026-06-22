<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| M3 — slug topic URLs (/topics/{id}-{slug}) + the board-list first-post excerpt. The numeric id still resolves
| (so old links never break); a bare-id or wrong-slug URL 301s to the canonical slugged form.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function slugForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

it('generates the canonical id-slug URL for a topic', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), slugForum(), 'PHP vs Node', 'tiptap_json', Content::doc('op'));

    expect($topic->getRouteKey())->toBe($topic->id.'-'.$topic->slug)
        ->and(route('topics.show', $topic))->toEndWith('/topics/'.$topic->id.'-'.$topic->slug);
});

it('resolves a topic by its numeric id and 301s a bare id to the slugged form', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), slugForum(), 'Hello World', 'tiptap_json', Content::doc('op'));

    $this->get('/topics/'.$topic->id)
        ->assertStatus(301)
        ->assertRedirect(route('topics.show', $topic)); // → /topics/{id}-{slug}
});

it('serves the canonical slugged URL with 200', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), slugForum(), 'Hello World', 'tiptap_json', Content::doc('op'));

    $this->get(route('topics.show', $topic))->assertOk()->assertSee('Hello World');
});

it('301s a wrong-slug URL to the canonical form', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), slugForum(), 'Hello World', 'tiptap_json', Content::doc('op'));

    $this->get('/topics/'.$topic->id.'-totally-wrong-slug')
        ->assertStatus(301)
        ->assertRedirect(route('topics.show', $topic));
});

it('preserves the query string across the canonical redirect', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), slugForum(), 'Hello World', 'tiptap_json', Content::doc('op'));

    $this->get('/topics/'.$topic->id.'?page=1')
        ->assertStatus(301)
        ->assertRedirect(route('topics.show', $topic).'?page=1');
});

it('shows the first-post excerpt on the board list', function () {
    $forum = slugForum();
    app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), $forum, 'A topic', 'tiptap_json', Content::doc('a distinctive opening sentence here'));

    $this->get(route('forums.show', $forum))->assertOk()->assertSee('a distinctive opening sentence here');
});
