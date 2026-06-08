<?php

// SPDX-License-Identifier: Apache-2.0

use App\Forum\StructureException;
use App\Forum\StructureService;
use App\Models\AuditLog;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function structure(): StructureService
{
    return app(StructureService::class);
}

function topicIn(Forum $forum, string $slug, bool $withPost = false): Topic
{
    $topic = Topic::create(['forum_id' => $forum->id, 'title' => ucfirst($slug), 'slug' => $slug]);
    if ($withPost) {
        Post::create([
            'topic_id' => $topic->id,
            'body_format' => 'tiptap_json',
            'body_canonical' => '{"type":"doc"}',
            'approved_state' => 'approved',
        ]);
    }

    return $topic;
}

it('creates a category and a board, appending sibling positions', function () {
    $cat = structure()->create(['title' => 'General', 'type' => 'category']);
    $a = structure()->create(['title' => 'Lobby', 'type' => 'forum', 'parent_id' => $cat->id]);
    $b = structure()->create(['title' => 'Off-topic', 'type' => 'forum', 'parent_id' => $cat->id]);

    expect($cat->isCategory())->toBeTrue();
    expect($a->parent_id)->toBe($cat->id);
    expect($a->position)->toBe(0);
    expect($b->position)->toBe(1);
    expect(AuditLog::where('action', 'forum.created')->count())->toBe(3);
});

it('refuses to give a category a parent', function () {
    $cat = structure()->create(['title' => 'Top', 'type' => 'category']);
    structure()->create(['title' => 'Sub', 'type' => 'category', 'parent_id' => $cat->id]);
})->throws(StructureException::class);

it('makes a brand-new board immediately usable via inherited role permissions', function () {
    $board = structure()->create(['title' => 'New Board', 'type' => 'forum']);

    // No per-node ACL rows are written; the board inherits the global presets through the scope chain.
    expect(User::guest()->canDo('forum.view', $board->permissionScope()))->toBeTrue();

    $member = Users::inGroups(['members']);
    expect($member->canDo('post.create', $board->permissionScope()))->toBeTrue();
});

it('moves topics to a destination board and recomputes both boards counters', function () {
    $a = structure()->create(['title' => 'A', 'type' => 'forum']);
    $b = structure()->create(['title' => 'B', 'type' => 'forum']);
    topicIn($a, 't1', withPost: true);
    topicIn($a, 't2', withPost: true);

    expect($a->fresh()->topic_count)->toBe(2);
    expect($a->fresh()->post_count)->toBe(2);

    $moved = structure()->moveContents($a->fresh(), $b->fresh());

    expect($moved)->toBe(2);
    expect($a->fresh()->topic_count)->toBe(0);
    expect($a->fresh()->post_count)->toBe(0);
    expect($b->fresh()->topic_count)->toBe(2);
    expect($b->fresh()->post_count)->toBe(2);
    expect(Topic::where('forum_id', $b->id)->count())->toBe(2);
});

it('refuses to delete a board with topics when no destination is chosen', function () {
    $a = structure()->create(['title' => 'A', 'type' => 'forum']);
    topicIn($a, 't1');

    expect(fn () => structure()->delete($a->fresh()))->toThrow(StructureException::class);
    expect(Forum::whereKey($a->id)->exists())->toBeTrue(); // untouched
});

it('deletes a board with topics by moving them to a destination, audited', function () {
    $a = structure()->create(['title' => 'A', 'type' => 'forum']);
    $b = structure()->create(['title' => 'B', 'type' => 'forum']);
    topicIn($a, 't1', withPost: true);

    $moved = structure()->delete($a->fresh(), $b->fresh());

    expect($moved)->toBe(1);
    expect(Forum::whereKey($a->id)->exists())->toBeFalse(); // soft-deleted
    expect(Topic::where('forum_id', $b->id)->count())->toBe(1);
    expect(AuditLog::where('action', 'forum.deleted')->exists())->toBeTrue();
});

it('refuses to delete a category that still has boards', function () {
    $cat = structure()->create(['title' => 'Cat', 'type' => 'category']);
    structure()->create(['title' => 'Board', 'type' => 'forum', 'parent_id' => $cat->id]);

    expect(fn () => structure()->delete($cat->fresh()))->toThrow(StructureException::class);
});

it('reorders siblings up and down', function () {
    $a = structure()->create(['title' => 'A', 'type' => 'category']);
    $b = structure()->create(['title' => 'B', 'type' => 'category']);
    $c = structure()->create(['title' => 'C', 'type' => 'category']);

    structure()->reorder($c->fresh(), 'up'); // A, C, B

    expect(Forum::find($a->id)->position)->toBe(0);
    expect(Forum::find($c->id)->position)->toBe(1);
    expect(Forum::find($b->id)->position)->toBe(2);
});

it('rebuilds subtree paths on reparent and blocks cycles', function () {
    $cat = structure()->create(['title' => 'Cat', 'type' => 'category']);
    $board = structure()->create(['title' => 'Board', 'type' => 'forum']);
    $sub = structure()->create(['title' => 'Sub', 'type' => 'forum', 'parent_id' => $board->id]);

    structure()->update($board->fresh(), ['title' => 'Board', 'parent_id' => $cat->id]);

    expect(Forum::find($board->id)->parent_id)->toBe($cat->id);
    expect(Forum::find($sub->id)->path)->toContain('/'.$cat->id.'/'.$board->id.'/'.$sub->id.'/');

    // Reparenting a node into its own descendant is a cycle → rejected.
    expect(fn () => structure()->update($board->fresh(), ['title' => 'Board', 'parent_id' => $sub->id]))
        ->toThrow(StructureException::class);
});

it('drives the move-contents delete flow through the panel', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $a = structure()->create(['title' => 'A', 'type' => 'forum']);
    $b = structure()->create(['title' => 'B', 'type' => 'forum']);
    topicIn($a, 't1');

    Livewire::actingAs($admin)->test('admin.structure')
        ->call('askDelete', $a->id)
        ->set('destinationId', $b->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect(Forum::whereKey($a->id)->exists())->toBeFalse();
    expect(Topic::where('forum_id', $b->id)->count())->toBe(1);
});

it('forbids a non-admin from the structure component (self-guard)', function () {
    $member = Users::inGroups(['members']);

    Livewire::actingAs($member)->test('admin.structure')->assertForbidden();
});
