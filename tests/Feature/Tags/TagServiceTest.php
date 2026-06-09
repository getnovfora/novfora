<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\TagException;
use App\Forum\TagService;
use App\Models\Forum;
use App\Models\Tag;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function tagForum(): Forum
{
    return Forum::firstOrCreate(['slug' => 'tag-test'], ['title' => 'Tag Test Forum', 'type' => 'forum']);
}

function makeTaggedTopic(Forum $forum): Topic
{
    $author = Users::inGroups(['members', 'tl2']);

    return app(PostService::class)->createTopic($author, $forum, 'Topic '.uniqid(), 'markdown', ['source' => 'Body.']);
}

// ── normalizeName ──────────────────────────────────────────────────────────────────────────────

it('strips HTML from a tag name', function () {
    $svc = app(TagService::class);
    expect($svc->normalizeName('<b>PHP</b>'))->toBe('PHP');
});

it('collapses internal whitespace', function () {
    $svc = app(TagService::class);
    expect($svc->normalizeName('  foo   bar  '))->toBe('foo bar');
});

it('bounds the name to 50 characters', function () {
    $svc = app(TagService::class);
    $long = str_repeat('a', 60);
    expect(mb_strlen($svc->normalizeName($long)))->toBe(50);
});

// ── slugFor ───────────────────────────────────────────────────────────────────────────────────

it('generates a URL slug from a name', function () {
    $svc = app(TagService::class);
    expect($svc->slugFor('Hello World'))->toBe('hello-world');
});

// ── create (mint) ──────────────────────────────────────────────────────────────────────────────

it('mints a new tag and persists it', function () {
    $svc = app(TagService::class);
    $tag = $svc->create('Laravel');

    expect($tag->name)->toBe('Laravel')
        ->and($tag->slug)->toBe('laravel')
        ->and($tag->usage_count)->toBe(0)
        ->and(Tag::where('slug', 'laravel')->exists())->toBeTrue();
});

it('deduplicates: minting an existing slug returns the existing tag', function () {
    $svc = app(TagService::class);
    $first = $svc->create('PHP');
    $second = $svc->create('php'); // same slug after normalization

    expect($second->id)->toBe($first->id)
        ->and(Tag::count())->toBe(1);
});

it('throws TagException when the normalised name is empty', function () {
    $svc = app(TagService::class);
    expect(fn () => $svc->create('  '))->toThrow(TagException::class);
});

// ── existing ──────────────────────────────────────────────────────────────────────────────────

it('returns only tags that already exist in the DB', function () {
    $svc = app(TagService::class);
    Tag::create(['name' => 'Existing', 'slug' => 'existing', 'usage_count' => 0]);

    $found = $svc->existing(['Existing', 'Not Yet Created']);
    expect($found)->toHaveCount(1)
        ->and($found->first()->slug)->toBe('existing');
});

it('returns an empty collection when no names match', function () {
    $svc = app(TagService::class);
    expect($svc->existing(['ghost', 'phantom']))->toHaveCount(0);
});

// ── syncTopicTags + usage_count authoritative recompute ───────────────────────────────────────

it('usage_count is 3 after applying a tag to 3 topics', function () {
    $svc = app(TagService::class);
    $forum = tagForum();
    $tag = $svc->create('popular');

    $topics = [makeTaggedTopic($forum), makeTaggedTopic($forum), makeTaggedTopic($forum)];
    foreach ($topics as $topic) {
        $svc->syncTopicTags($topic, [$tag->id]);
    }

    expect(Tag::find($tag->id)->usage_count)->toBe(3);
});

it('usage_count drops to 2 after removing the tag from one of 3 topics', function () {
    $svc = app(TagService::class);
    $forum = tagForum();
    $tag = $svc->create('popular');

    $topics = [makeTaggedTopic($forum), makeTaggedTopic($forum), makeTaggedTopic($forum)];
    foreach ($topics as $topic) {
        $svc->syncTopicTags($topic, [$tag->id]);
    }

    // Remove the tag from the first topic.
    $svc->syncTopicTags($topics[0], []);

    expect(Tag::find($tag->id)->usage_count)->toBe(2);
});

it('sync replaces the entire tag set on a topic', function () {
    $svc = app(TagService::class);
    $forum = tagForum();
    $tagA = $svc->create('alpha');
    $tagB = $svc->create('beta');
    $topic = makeTaggedTopic($forum);

    $svc->syncTopicTags($topic, [$tagA->id, $tagB->id]);
    expect($topic->tags()->count())->toBe(2);

    // Sync to only tagB → tagA should be removed.
    $svc->syncTopicTags($topic, [$tagB->id]);
    expect($topic->tags()->pluck('tags.id')->all())->toBe([$tagB->id]);
    expect(Tag::find($tagA->id)->usage_count)->toBe(0);
    expect(Tag::find($tagB->id)->usage_count)->toBe(1);
});

it('usage_count recomputes authoritatively (never drifts from the source table)', function () {
    $svc = app(TagService::class);
    $forum = tagForum();
    $tag = $svc->create('recount');

    $topic = makeTaggedTopic($forum);
    $svc->syncTopicTags($topic, [$tag->id]);
    expect(Tag::find($tag->id)->usage_count)->toBe(1);

    // Manually corrupt the count.
    Tag::where('id', $tag->id)->update(['usage_count' => 999]);

    // A sync corrects it.
    $svc->syncTopicTags($topic, [$tag->id]);
    expect(Tag::find($tag->id)->usage_count)->toBe(1);
});
