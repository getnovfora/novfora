<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\PrefixException;
use App\Forum\PrefixManager;
use App\Models\AuditLog;
use App\Models\Forum;
use App\Models\Prefix;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function pm(): PrefixManager
{
    return app(PrefixManager::class);
}

function prefixForum(): Forum
{
    return Forum::firstOrCreate(['slug' => 'general'], ['title' => 'General', 'type' => 'forum']);
}

it('creates a global prefix (forum_id = null)', function () {
    $prefix = pm()->create(['label' => 'Guide', 'color_token' => 'blue', 'position' => 0]);

    expect($prefix->label)->toBe('Guide')
        ->and($prefix->color_token)->toBe('blue')
        ->and($prefix->forum_id)->toBeNull();
});

it('creates a forum-specific prefix', function () {
    $forum = prefixForum();
    $prefix = pm()->create(['label' => 'Question', 'color_token' => 'indigo', 'forum_id' => $forum->id]);

    expect($prefix->forum_id)->toBe((int) $forum->id);
});

it('updates label and colour', function () {
    $prefix = pm()->create(['label' => 'Old', 'color_token' => 'red']);
    $updated = pm()->update($prefix, ['label' => 'New', 'color_token' => 'green']);

    expect($updated->label)->toBe('New')
        ->and($updated->color_token)->toBe('green');
});

it('stores null for an invalid colour token', function () {
    $prefix = pm()->create(['label' => 'X', 'color_token' => 'rainbow']);
    expect($prefix->color_token)->toBeNull();
});

it('throws PrefixException when label is empty on create', function () {
    pm()->create(['label' => '']);
})->throws(PrefixException::class);

it('throws PrefixException when label is empty on update', function () {
    $prefix = pm()->create(['label' => 'Valid']);
    pm()->update($prefix, ['label' => '   ']);
})->throws(PrefixException::class);

it('delete nulls topics.prefix_id for topics using it (no orphan)', function () {
    $forum = prefixForum();
    $author = Users::inGroups(['members', 'tl2']);
    $prefix = pm()->create(['label' => 'Solved']);

    // Create a topic with this prefix via PostService.
    $topic = app(PostService::class)->createTopic($author, $forum, 'My Topic', 'markdown', ['source' => 'Body.'], $prefix->id);
    expect((int) $topic->prefix_id)->toBe((int) $prefix->id);

    pm()->delete($prefix);

    expect(Prefix::find($prefix->id))->toBeNull();
    expect($topic->fresh()->prefix_id)->toBeNull();
});

it('delete wraps null-out + delete in a transaction (both succeed or neither)', function () {
    $prefix = pm()->create(['label' => 'T']);
    pm()->delete($prefix);

    expect(Prefix::find($prefix->id))->toBeNull();
});

it('audit-logs prefix created', function () {
    pm()->create(['label' => 'Guide']);
    $entry = AuditLog::where('action', 'prefix.created')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->changes['label'])->toBe('Guide');
});

it('audit-logs prefix deleted', function () {
    $prefix = pm()->create(['label' => 'Gone']);
    pm()->delete($prefix);
    $entry = AuditLog::where('action', 'prefix.deleted')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->changes['label'])->toBe('Gone');
});
