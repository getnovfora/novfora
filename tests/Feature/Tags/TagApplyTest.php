<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\PostRateLimiter;
use App\Forum\PollService;
use App\Forum\PostService;
use App\Forum\TagService;
use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Tag;
use App\Models\Topic;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

function applyForum(): Forum
{
    return Forum::firstOrCreate(['slug' => 'apply-board'], ['title' => 'Apply Board', 'type' => 'forum']);
}

// ── member (tag.apply, no tag.create) can apply an existing tag ────────────────────────────────

it('a member can apply an existing tag to their topic via the create-topic composer', function () {
    $forum = applyForum();
    $author = Users::inGroups(['members', 'tl0']); // TL0: tag.apply yes, tag.create NEVER
    $this->actingAs($author);

    $existingTag = Tag::create(['name' => 'laravel', 'slug' => 'laravel', 'usage_count' => 0]);

    Livewire::test('forum.create-topic', ['forumId' => $forum->id])
        ->set('title', 'My Tagged Topic')
        ->set('markdownSource', 'Body text here.')
        ->set('format', 'markdown')
        ->set('tags', ['laravel']) // existing tag name
        ->call('save', app(PostService::class), app(PostRateLimiter::class), app(PollService::class), app(TagService::class))
        ->assertRedirect();

    // The topic should have been tagged.
    $topic = Topic::where('title', 'My Tagged Topic')->firstOrFail();
    expect($topic->tags()->pluck('slug')->all())->toContain('laravel');
    expect(Tag::find($existingTag->id)->usage_count)->toBe(1);
});

// ── TL0 member: a new tag name is silently dropped (can't mint) ───────────────────────────────

it('a TL0 member typing a new tag name has it silently dropped (no mint permission)', function () {
    $forum = applyForum();
    $author = Users::inGroups(['members', 'tl0']); // TL0: tag.create NEVER
    $this->actingAs($author);

    Livewire::test('forum.create-topic', ['forumId' => $forum->id])
        ->set('title', 'Topic No New Tag')
        ->set('markdownSource', 'Body.')
        ->set('format', 'markdown')
        ->set('tags', ['brand-new-tag-xyz']) // not in DB; TL0 cannot mint
        ->call('save', app(PostService::class), app(PostRateLimiter::class), app(PollService::class), app(TagService::class))
        ->assertRedirect();

    // The tag must NOT have been created.
    expect(Tag::where('slug', 'brand-new-tag-xyz')->exists())->toBeFalse();

    $topic = Topic::where('title', 'Topic No New Tag')->firstOrFail();
    expect($topic->tags()->count())->toBe(0);
});

// ── TL1 member can mint a new tag ─────────────────────────────────────────────────────────────

it('a TL1 member can mint a new tag via the create-topic composer', function () {
    $forum = applyForum();
    $author = Users::inGroups(['members', 'tl1']); // TL1: tag.create ALLOW via $trusted
    $this->actingAs($author);

    Livewire::test('forum.create-topic', ['forumId' => $forum->id])
        ->set('title', 'Topic With New Tag')
        ->set('markdownSource', 'Body.')
        ->set('format', 'markdown')
        ->set('tags', ['fresh-new-tag']) // not in DB; TL1 can mint
        ->call('save', app(PostService::class), app(PostRateLimiter::class), app(PollService::class), app(TagService::class))
        ->assertRedirect();

    // The new tag should exist.
    expect(Tag::where('slug', 'fresh-new-tag')->exists())->toBeTrue();

    $topic = Topic::where('title', 'Topic With New Tag')->firstOrFail();
    expect($topic->tags()->pluck('slug')->all())->toContain('fresh-new-tag');
});

// ── tags render on the topic page ─────────────────────────────────────────────────────────────

it('tags appear as chips on the topic page', function () {
    $forum = applyForum();
    $author = Users::inGroups(['members', 'tl2']);
    $this->actingAs($author);

    $svc = app(TagService::class);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Chipped Topic', 'markdown', ['source' => 'Body.']);
    $tag = $svc->create('chip-tag');
    $svc->syncTopicTags($topic, [$tag->id]);

    $this->actingAs($author)
        ->get(route('topics.show', $topic))
        ->assertOk()
        ->assertSee('chip-tag');
});

// ── tags.show: permission-filtered topic listing ─────────────────────────────────────────────

it('tags.show lists topics carrying that tag', function () {
    $forum = applyForum();
    $author = Users::inGroups(['members', 'tl2']);
    $svc = app(TagService::class);

    $topic = app(PostService::class)->createTopic($author, $forum, 'Tagged Topic', 'markdown', ['source' => 'Body.']);
    $tag = $svc->create('visible-tag');
    $svc->syncTopicTags($topic, [$tag->id]);

    $this->actingAs($author)
        ->get(route('tags.show', $tag))
        ->assertOk()
        ->assertSee('Tagged Topic');
});

it('tags.show hides topics in forums the viewer cannot see', function () {
    $forum = applyForum();
    $author = Users::inGroups(['members', 'tl2']);
    $svc = app(TagService::class);

    $topic = app(PostService::class)->createTopic($author, $forum, 'Hidden Topic', 'markdown', ['source' => 'Body.']);
    $tag = $svc->create('hidden-tag');
    $svc->syncTopicTags($topic, [$tag->id]);

    // Block the viewer from seeing this forum.
    $viewer = Users::inGroups(['members']);
    AclEntry::create([
        'permission_key' => 'forum.view',
        'holder_type' => 'user',
        'holder_id' => $viewer->id,
        'scope_type' => 'forum',
        'scope_id' => $forum->id,
        'value' => PermissionValue::Never->value,
    ]);
    Cache::flush();

    $this->actingAs($viewer)
        ->get(route('tags.show', $tag))
        ->assertOk()
        ->assertDontSee('Hidden Topic');
});
